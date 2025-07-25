<?php
// public/proses_store_pinecone.php
/*
 * 🤖 Aplikasi Brooker AI / Calo AI Palugada
 * (Apa elo mau, gw ada)
 * Dibuat oleh: Kukuh TW
 *
 * 📧 Email     : kukuhtw@gmail.com
 * 📱 WhatsApp  : 628129893706
 * 📷 Instagram : @kukuhtw
 * 🐦 X/Twitter : @kukuhtw
 * 👍 Facebook  : https://www.facebook.com/kukuhtw
 * 💼 LinkedIn  : https://id.linkedin.com/in/kukuhtw
*/
require __DIR__ . '/../bootstrap.php';
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

/** @var PDO $conn */
$conn = $db->getConnection();

// 1. Ambil konfigurasi dari settings
$keys = ['OPEN_AI_KEY','PINECONE_API_KEY','PINECONE_INDEX_NAME','PINECONE_NAMESPACE'];
$in  = str_repeat('?,', count($keys) - 1) . '?';
$stmt = $conn->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($in)");
$stmt->execute($keys);
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$openaiApiKey      = $settings['OPEN_AI_KEY']         ?? '';
$pineconeApiKey    = $settings['PINECONE_API_KEY']    ?? '';
$pineconeIndexName = $settings['PINECONE_INDEX_NAME'] ?? '';
$namespace         = $settings['PINECONE_NAMESPACE']  ?? '';

if (!$openaiApiKey || !$pineconeApiKey || !$pineconeIndexName || !$namespace) {
    die('Konfigurasi Pinecone/OpenAI belum lengkap di tabel settings.');
}

// 2. Ambil parameter data_id
$dataId = isset($_GET['data_id']) ? (int)$_GET['data_id'] : 0;
if ($dataId <= 0) {
    die('Parameter data_id tidak valid.');
}

// 3. Ambil description dan metadata_pinecone
$stmt = $conn->prepare("
    SELECT description, metadata_pinecone 
    FROM data_inventory 
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $dataId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    die("Data dengan ID {$dataId} tidak ditemukan.");
}

$description       = "ID:". $dataId. "\n".$row['description'];
$rawMetadata       = $row['metadata_pinecone'];
$metadataForVector = json_decode($rawMetadata, true) ?: [];

// 4. Panggil OpenAI untuk embed teks
$embeddings = call_embeddings_openai($openaiApiKey, $description);

// 5. Upsert ke Pinecone
$resultPinecone = store_index_to_pinecone(
    $pineconeApiKey,
    $pineconeIndexName,
    (string)$dataId,
    $namespace,
    $embeddings,
    $metadataForVector
);

// 6. Tentukan flag ispinecone berdasarkan response
$ispine = 0;
if (isset($resultPinecone['upserted_count']) && $resultPinecone['upserted_count'] > 0) {
    $ispine = 1;
} elseif (isset($resultPinecone['code']) || isset($resultPinecone['error'])) {
    // tetap 0
    Logger::error("Pinecone upsert error for ID {$dataId}: " . json_encode($resultPinecone));
} else {
    // fallback: jika struktur response berbeda, anggap sukses
    $ispine = 1;
}

// **Tambah di sini**: kalau gagal (ispine=0), kosongkan metadata_pinecone
$shouldClearMeta = ($ispine === 0);

// 7. Update database di luar function
$sql = "
    UPDATE data_inventory
    SET ispinecone     = :ispinecone,
        result_pinecone = :res,
        lastupdatedate  = NOW()
";
// jika gagal, tambahkan pengosongan metadata_pinecone
if ($shouldClearMeta) {
    $sql .= ", metadata_pinecone = ''";
}
$sql .= " WHERE id = :id";

$update = $conn->prepare($sql);
$update->execute([
    ':ispinecone' => $ispine,
    ':res'        => json_encode($resultPinecone),
    ':id'         => $dataId,
]);


// Beri notifikasi ke UI/admin
if ($ispine) {
    $_SESSION['info'] = ['status'=>'success','message'=>"Sukses upsert ke Pinecone untuk ID {$dataId}"];
} else {
    $_SESSION['info'] = ['status'=>'danger','message'=> "Gagal upsert ke Pinecone; lihat log untuk detail."];
}

header('Location: view_data.php');
exit;



/**
 * @param string $apiKey
 * @param string $text
 * @return float[]
 */
function call_embeddings_openai(string $apiKey, string $text): array {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'input' => $text,
            'model' => 'text-embedding-ada-002',
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        die('OpenAI curl error: ' . curl_error($ch));
    }
    $data = json_decode($resp, true);
    curl_close($ch);
    return $data['data'][0]['embedding'] ?? [];
}

/**
 * @param string  $apiKey
 * @param string  $index
 * @param string  $id
 * @param string  $namespace
 * @param float[] $values
 * @param array   $metadata
 * @return array
 */
function store_index_to_pinecone(
    string $apiKey,
    string $index,
    string $id,
    string $namespace,
    array  $values,
    array  $metadata
): array {
    $url = "https://{$index}.pinecone.io/vectors/upsert";
    $payload = [
        'namespace' => $namespace,
        'vectors'   => [[
            'id'       => $id,
            'values'   => $values,
            'metadata' => $metadata,
        ]],
    ];
// Debug before request
    Logger::debug("[Pinecone] Upsert URL: {$url}");
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Api-Key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        die('Pinecone curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    return json_decode($resp, true) ?: [];
}

?>