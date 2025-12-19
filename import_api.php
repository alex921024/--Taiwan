<?php

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 0) {
    die("<div style='background:#121212; color:#ff5252; height:100vh; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif;'>
            <h2 style='border: 1px solid #ff5252; padding: 20px; border-radius: 8px;'>🚫 存取拒絕：權限不足</h2>
            <p style='color: #aaa;'>此頁面僅限管理員帳號存取。請先以管理員身份登入。</p>
            <a href='index.php' style='color:#3d5afe; text-decoration:none; margin-top:20px;'>← 返回查詢首頁</a>
         </div>");
}

set_time_limit(0);
ini_set('memory_limit', '-1');

$host = 'localhost'; $db = 'weather_system'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

$cityMap = [
    'F-D0047-063' => '臺北市', 'F-D0047-071' => '新北市', 'F-D0047-055' => '桃園市',
    'F-D0047-075' => '臺中市', 'F-D0047-079' => '臺南市', 'F-D0047-067' => '高雄市',
    'F-D0047-003' => '宜蘭縣', 'F-D0047-007' => '新竹縣', 'F-D0047-011' => '苗栗縣',
    'F-D0047-015' => '彰化縣', 'F-D0047-019' => '南投縣', 'F-D0047-023' => '雲林縣',
    'F-D0047-027' => '嘉義縣', 'F-D0047-031' => '屏東縣', 'F-D0047-035' => '臺東縣',
    'F-D0047-039' => '花蓮縣', 'F-D0047-043' => '澎湖縣', 'F-D0047-047' => '基隆市',
    'F-D0047-051' => '新竹市', 'F-D0047-059' => '嘉義市', 'F-D0047-083' => '金門縣',
    'F-D0047-087' => '連江縣'
];

$message = '';
$sslOptions = [ "ssl" => [ "verify_peer" => false, "verify_peer_name" => false ] ];
$context = stream_context_create($sslOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'weather' && isset($_POST['dataid'])) {
        $dataid = $_POST['dataid'];
        
        if (array_key_exists($dataid, $cityMap)) {
            $cityName = $cityMap[$dataid];
            // import_api.php 第 47 行
            $apiUrl = "https://opendata.cwa.gov.tw/api/v1/rest/datastore/{$dataid}?Authorization=YOUR_CWA_API_KEY&format=JSON";

            
            $jsonContent = @file_get_contents($apiUrl, false, $context);
            
            if ($jsonContent) {
                $data = json_decode($jsonContent, true);
                if ($data && $data['success'] === 'true') {
                    $locations = $data['records']['Locations'][0]['Location'];
                    $insertCount = 0; $skipCount = 0;

                    foreach ($locations as $location) {
                        $locationName = $location['LocationName'];
                        $geocode = $location['Geocode'];
                        $lat = $location['Latitude'];
                        $lon = $location['Longitude'];

                        $stmt = $pdo->prepare("SELECT id FROM locations WHERE location_name = ? AND city_name = ?");
                        $stmt->execute([$locationName, $cityName]);
                        $locRow = $stmt->fetch();

                        if ($locRow) {
                            $locationId = $locRow['id'];
                        } else {
                            $insertLoc = $pdo->prepare("INSERT INTO locations (city_name, location_name, geocode, lat, lon) VALUES (?, ?, ?, ?, ?)");
                            $insertLoc->execute([$cityName, $locationName, $geocode, $lat, $lon]);
                            $locationId = $pdo->lastInsertId();
                        }

                        foreach ($location['WeatherElement'] as $element) {
                            $elementName = $element['ElementName'];
                            foreach ($element['Time'] as $time) {
                                $startTime = date('Y-m-d H:i:s', strtotime($time['StartTime']));
                                $endTime = date('Y-m-d H:i:s', strtotime($time['EndTime']));
                                $value = reset($time['ElementValue']);
                                $value = reset($value);
                                $unit = '';

                                $checkStmt = $pdo->prepare("SELECT id FROM forecasts WHERE location_id = ? AND element_name = ? AND start_time = ?");
                                $checkStmt->execute([$locationId, $elementName, $startTime]);
                                
                                if ($checkStmt->fetch()) {
                                    $skipCount++;
                                } else {
                                    $insertStmt = $pdo->prepare("INSERT INTO forecasts (location_id, element_name, start_time, end_time, value, unit) VALUES (?, ?, ?, ?, ?, ?)");
                                    $insertStmt->execute([$locationId, $elementName, $startTime, $endTime, $value, $unit]);
                                    $insertCount++;
                                }
                            }
                        }
                    }
                    $message = "<div class='alert success'><strong>{$cityName}</strong> 天氣資料更新成功！(新增: $insertCount / 跳過: $skipCount)</div>";
                } else {
                    $message = "<div class='alert error'>天氣資料解析失敗。</div>";
                }
            } else {
                $message = "<div class='alert error'>無法下載天氣資料 (API 連線失敗)。</div>";
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'aqi') {
        $aqiApiUrl = "https://data.moenv.gov.tw/api/v2/aqx_p_432?api_key=0645612e-c67b-48b3-9428-fe741b582531&limit=1000&sort=ImportDate%20desc&format=JSON";
        
        $jsonContent = @file_get_contents($aqiApiUrl, false, $context);
        
        if ($jsonContent) {
            $data = json_decode($jsonContent, true);
            if (isset($data['records'])) {
                $pdo->exec("TRUNCATE TABLE aqi_data");
                
                $count = 0;
                $insertStmt = $pdo->prepare("INSERT INTO aqi_data (county, sitename, aqi, status, longitude, latitude, publishtime) VALUES (?, ?, ?, ?, ?, ?, ?)");

                foreach ($data['records'] as $row) {
                    $insertStmt->execute([
                        $row['county'], 
                        $row['sitename'], 
                        $row['aqi'], 
                        $row['status'],
                        $row['longitude'], 
                        $row['latitude'],  
                        date('Y-m-d H:i:s', strtotime($row['publishtime']))
                    ]);
                    $count++;
                }
                $message = "<div class='alert success'>AQI 空氣品質更新完成！(已下載 {$count} 個測站詳細座標)</div>";
            } else {
                $message = "<div class='alert error'>AQI 資料格式錯誤。</div>";
            }
        } else {
            $message = "<div class='alert error'>無法下載 AQI 資料 (API 連線失敗)。</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資料庫管理後台</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; background: #121212; color: #e0e0e0; padding: 40px; display: flex; justify-content: center; min-height: 80vh; margin: 0; }
        .container { background: #1e1e1e; padding: 40px; border-radius: 12px; border: 1px solid #333; width: 100%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        h2 { color: #3d5afe; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 15px; text-align: center; }
        .section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #333; }
        .section:last-child { border-bottom: none; }
        h3 { margin-bottom: 15px; color: #aaa; font-size: 1.1em; }
        form { display: flex; flex-direction: column; gap: 15px; }
        select { padding: 12px; background: #2c2c2c; color: white; border: 1px solid #444; border-radius: 8px; font-size: 16px; cursor: pointer; }
        select:focus { outline: none; border-color: #3d5afe; }
        button { padding: 12px; background: #3d5afe; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; font-weight: bold; transition: 0.3s; }
        button:hover { background: #536dfe; }
        .btn-aqi { background: #00897b; }
        .btn-aqi:hover { background: #00a090; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; line-height: 1.6; }
        .success { background: rgba(76, 175, 80, 0.15); border: 1px solid #2e7d32; color: #81c784; }
        .error { background: rgba(244, 67, 54, 0.15); border: 1px solid #c62828; color: #e57373; }
        a { display: block; margin-top: 20px; color: #757575; text-decoration: none; font-size: 0.9em; text-align: center; }
        a:hover { color: white; }
        
        #loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.85); display: none;
            justify-content: center; align-items: center; flex-direction: column; z-index: 9999;
            backdrop-filter: blur(5px);
        }
        .spinner {
            border: 5px solid #333; border-top: 5px solid #3d5afe; border-radius: 50%;
            width: 50px; height: 50px; animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { color: #fff; margin-top: 20px; font-size: 1.2rem; font-weight: bold; letter-spacing: 1px; }
        .loading-subtext { color: #aaa; margin-top: 10px; font-size: 0.9rem; }
    </style>
    <script>
        function showLoading() { document.getElementById('loading-overlay').style.display = 'flex'; }
    </script>
</head>
<body>
    <div id="loading-overlay">
        <div class="spinner"></div>
        <div class="loading-text"> 資料更新中...</div>
        <div class="loading-subtext">請勿關閉視窗</div>
    </div>

    <div class="container">
        <h2>資料庫管理後台</h2>
        <?= $message ?>

        <div class="section">
            <h3>1. 天氣預報資料更新 (中央氣象署)</h3>
            <form method="POST" action="" onsubmit="showLoading()">
                <input type="hidden" name="action" value="weather">
                <select name="dataid" required>
                    <option value="" disabled selected>-- 請選擇縣市 --</option>
                    <?php foreach ($cityMap as $id => $name): ?>
                        <option value="<?= $id ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">下載並匯入天氣資料</button>
            </form>
        </div>

        <div class="section">
            <h3>2. 空氣品質資料更新 (環境部)</h3>
            <form method="POST" action="" onsubmit="showLoading()">
                <input type="hidden" name="action" value="aqi">
                <button type="submit" class="btn-aqi">更新全國 AQI (含詳細測站)</button>
            </form>
        </div>
        
        <a href="index.php">← 返回查詢首頁</a>
    </div>
</body>

</html>
