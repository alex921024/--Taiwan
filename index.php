<?php
session_start(); 

$host = 'localhost'; $db = 'weather_system'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

// --- 新增：註冊功能處理邏輯 ---
$reg_error = '';
$reg_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_action'])) {
    $reg_user = trim($_POST['reg_username']);
    $reg_pass = $_POST['reg_password'];

    if (mb_strlen($reg_user, 'utf-8') >= 10) {
        $reg_error = '註冊失敗：帳號需小於 10 個字';
    } elseif (strlen($reg_pass) >= 20) {
        $reg_error = '註冊失敗：密碼需小於 20 個字';
    } else {
        // 檢查帳號是否重複
        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->execute([$reg_user]);
        if ($stmt_check->fetch()) {
            $reg_error = '註冊失敗：帳號已存在';
        } else {
            $hashed_pass = hash('sha256', $reg_pass);
            $stmt_reg = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 1)");
            if ($stmt_reg->execute([$reg_user, $hashed_pass])) {
                $reg_success = '註冊成功！請登入';
            } else {
                $reg_error = '註冊失敗，請稍後再試';
            }
        }
    }
}
// ----------------------------

if (isset($_POST['action']) && $_POST['action'] === 'toggle_fav' && isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];
    $l_id = $_POST['loc_id'];
    
    $check = $pdo->prepare("SELECT * FROM user_favorites WHERE user_id = ? AND location_id = ?");
    $check->execute([$u_id, $l_id]);
    
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM user_favorites WHERE user_id = ? AND location_id = ?")->execute([$u_id, $l_id]);
        echo "removed";
    } else {
        $pdo->prepare("INSERT INTO user_favorites (user_id, location_id) VALUES (?, ?)")->execute([$u_id, $l_id]);
        echo "added";
    }
    exit; 
}

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $user_input = $_POST['username'];
    $pass_input = hash('sha256', $_POST['password']); 

    $stmt_user = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt_user->execute([$user_input, $pass_input]);
    $user_data = $stmt_user->fetch();

    if ($user_data) {
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['role'] = $user_data['role']; 
    } else {
        $login_error = '帳號或密碼錯誤';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$user_favs = [];
if (isset($_SESSION['user_id'])) {
    $stmt_f = $pdo->prepare("
        SELECT f.location_id as id, l.city_name as city, l.location_name as name 
        FROM user_favorites f 
        JOIN locations l ON f.location_id = l.id 
        WHERE f.user_id = ?
    ");
    $stmt_f->execute([$_SESSION['user_id']]);
    $user_favs = $stmt_f->fetchAll();
}

function getWeatherIcon($text) {
    if (strpos($text, '雷') !== false) return '⛈️';
    if (strpos($text, '雨') !== false) return '🌧️';
    if (strpos($text, '雪') !== false) return '❄️';
    if (strpos($text, '霧') !== false) return '🌫️';
    if (strpos($text, '陰') !== false) return '☁️';
    if (strpos($text, '多雲') !== false) return '🌥️';
    if (strpos($text, '晴') !== false) return '☀️';
    return '🌤️';
}

function getWeatherVideo($text) {
    if (strpos($text, '雷') !== false) return 'thunder.mp4';
    if (strpos($text, '雨') !== false) return 'rain.mp4';
    if (strpos($text, '雪') !== false) return 'snow.mp4';
    if (strpos($text, '霧') !== false) return 'fog.mp4';
    if (strpos($text, '陰') !== false) return 'cloudy.mp4';
    if (strpos($text, '多雲') !== false) return 'partly_cloudy.mp4'; 
    if (strpos($text, '晴') !== false) return 'sunny.mp4';
    return 'sunny.mp4'; 
}

function getAqiColor($aqi) {
    if ($aqi <= 50) return '#4caf50'; 
    if ($aqi <= 100) return '#ffeb3b'; 
    if ($aqi <= 150) return '#ff9800'; 
    if ($aqi <= 200) return '#f44336'; 
    if ($aqi <= 300) return '#9c27b0'; 
    return '#795548'; 
}

function getUviColor($uvi) {
    $uvi = intval($uvi);
    if ($uvi <= 2) return '#4caf50'; 
    if ($uvi <= 5) return '#ffeb3b'; 
    if ($uvi <= 7) return '#ff9800'; 
    if ($uvi <= 10) return '#f44336'; 
    return '#9c27b0'; 
}

$week_map = ['Sun'=>'週日', 'Mon'=>'週一', 'Tue'=>'週二', 'Wed'=>'週三', 'Thu'=>'週四', 'Fri'=>'週五', 'Sat'=>'週六'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    $loc_id = $_POST['loc_id'];
    $target_time = $_POST['target_time'];
    $user_name = isset($_SESSION['username']) ? $_SESSION['username'] : '匿名'; 
    $content = htmlspecialchars($_POST['content']);
    
    $redirect_url = "?city=" . urlencode($_POST['current_city']) . 
                    "&location_id=" . $loc_id . 
                    "&search_date=" . $_POST['current_date'];

    if (!empty($content)) {
        $stmt_comment = $pdo->prepare("INSERT INTO weather_comments (location_id, target_time, user_name, content) VALUES (?, ?, ?, ?)");
        $stmt_comment->execute([$loc_id, $target_time, $user_name, $content]);
    }
    
    header("Location: " . $redirect_url);
    exit;
}

$cities = $pdo->query("SELECT DISTINCT city_name FROM locations WHERE city_name != ''")->fetchAll(PDO::FETCH_COLUMN);

$grouped_forecasts = [];
$weekly_forecasts = [];
$chart_labels = []; 
$chart_data_temp = [];
$chart_data_rh = [];

$selected_city = isset($_GET['city']) ? $_GET['city'] : ''; 
$selected_loc = isset($_GET['location_id']) ? $_GET['location_id'] : ''; 
$selected_date = isset($_GET['search_date']) && $_GET['search_date'] != '' ? $_GET['search_date'] : date('Y-m-d'); 
$location_name_display = '';
$current_loc_name_only = ''; 
$aqi_info = null;
$current_bg_video = 'sunny.mp4'; 
$header_uvi = null;

$districts = [];
if ($selected_city) {
    $stmt = $pdo->prepare("SELECT MIN(id) as id, location_name FROM locations WHERE city_name = ? GROUP BY location_name");
    $stmt->execute([$selected_city]);
    $districts = $stmt->fetchAll();

    $stmt_aqi = $pdo->prepare("SELECT AVG(aqi) as avg_aqi, MAX(status) as status_txt FROM aqi_data WHERE county = ?");
    $stmt_aqi->execute([$selected_city]);
    $aqi_result = $stmt_aqi->fetch();
    
    if ($aqi_result && $aqi_result['avg_aqi'] !== null) {
        $aqi_info = [
            'val' => round($aqi_result['avg_aqi']),
            'status' => $aqi_result['status_txt']
        ];
    }
}

if ($selected_loc != '') {
    $stmt_loc = $pdo->prepare("SELECT location_name, city_name FROM locations WHERE id = ?");
    $stmt_loc->execute([$selected_loc]);
    $loc_info = $stmt_loc->fetch();
    
    if ($loc_info) {
        $location_name_display = $loc_info['city_name'] . ' ' . $loc_info['location_name'];
        $current_loc_name_only = $loc_info['location_name'];
        if (!$selected_city) $selected_city = $loc_info['city_name'];
    }

    $list_start_time = date('Y-m-d 23:00:00', strtotime($selected_date . ' -1 day'));
    $list_end_time   = date('Y-m-d 11:00:00', strtotime($selected_date));

    $sql = "SELECT * FROM forecasts 
            WHERE location_id = ? 
            AND start_time BETWEEN ? AND ? 
            AND element_name IN ('平均溫度', '天氣現象', '12小時降雨機率', '天氣預報綜合描述', '平均相對濕度', '風速', '紫外線指數') 
            ORDER BY start_time ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selected_loc, $list_start_time, $list_end_time]);
    $raw_data = $stmt->fetchAll();

    if (!empty($raw_data)) {
        foreach ($raw_data as $first_row) {
            if ($first_row['element_name'] == '天氣現象') {
                $current_bg_video = getWeatherVideo($first_row['value']);
                break;
            }
        }
    }

    foreach ($raw_data as $row) {
        $time_key = strtotime($row['start_time']);
        if (!isset($grouped_forecasts[$time_key])) {
            $grouped_forecasts[$time_key] = [
                'start' => $row['start_time'], 'end' => $row['end_time'],
                'wx' => '', 't' => '', 'pop' => '0', 'rh' => '-', 'ws' => '-', 'uvi' => '-', 'desc' => ''
            ];
        }
        switch ($row['element_name']) {
            case '天氣現象': $grouped_forecasts[$time_key]['wx'] = $row['value']; break;
            case '平均溫度': $grouped_forecasts[$time_key]['t'] = $row['value']; break;
            case '12小時降雨機率': $grouped_forecasts[$time_key]['pop'] = $row['value']; break;
            case '平均相對濕度': $grouped_forecasts[$time_key]['rh'] = $row['value']; break;
            case '風速': $grouped_forecasts[$time_key]['ws'] = $row['value']; break;
            case '紫外線指數': $grouped_forecasts[$time_key]['uvi'] = $row['value']; break;
            case '天氣預報綜合描述': $grouped_forecasts[$time_key]['desc'] = $row['value']; break;
        }
    }
    ksort($grouped_forecasts);

    $chart_start = date('Y-m-d 00:00:00', strtotime($selected_date . ' -1 day'));
    $chart_end   = date('Y-m-d 23:59:59', strtotime($selected_date . ' +1 day'));

    $sql_chart = "SELECT start_time, element_name, value FROM forecasts 
                  WHERE location_id = ? 
                  AND start_time BETWEEN ? AND ?
                  AND element_name IN ('平均溫度', '平均相對濕度') 
                  ORDER BY start_time ASC";
    
    $stmt_chart = $pdo->prepare($sql_chart);
    $stmt_chart->execute([$selected_loc, $chart_start, $chart_end]);
    $chart_raw = $stmt_chart->fetchAll();

    $temp_chart_map = [];
    foreach ($chart_raw as $row) {
        $ts = strtotime($row['start_time']);
        if (!isset($temp_chart_map[$ts])) {
            $temp_chart_map[$ts] = ['t' => null, 'rh' => null, 'time' => $row['start_time']];
        }
        if ($row['element_name'] == '平均溫度') $temp_chart_map[$ts]['t'] = $row['value'];
        if ($row['element_name'] == '平均相對濕度') $temp_chart_map[$ts]['rh'] = $row['value'];
    }
    
    foreach ($temp_chart_map as $item) {
        if ($item['t'] !== null) { 
            $chart_labels[] = date('m/d H:i', strtotime($item['time']));
            $chart_data_temp[] = intval($item['t']);
            $chart_data_rh[]   = intval($item['rh']);
        }
    }

    if (!empty($grouped_forecasts)) {
        $first_item = reset($grouped_forecasts);
        $header_uvi = isset($first_item['uvi']) ? $first_item['uvi'] : null;
    }

    $week_sql = "SELECT start_time, element_name, value FROM forecasts 
                 WHERE location_id = ? 
                 AND start_time >= CURDATE() 
                 AND element_name IN ('平均溫度', '天氣現象')
                 ORDER BY start_time ASC";
    
    $stmt_week = $pdo->prepare($week_sql);
    $stmt_week->execute([$selected_loc]);
    $week_raw = $stmt_week->fetchAll();

    $temp_week_map = [];
    foreach ($week_raw as $row) {
        $date_key = date('Y-m-d', strtotime($row['start_time']));
        if (!isset($temp_week_map[$date_key])) {
            $temp_week_map[$date_key] = ['temps' => [], 'wxs' => []];
        }
        if ($row['element_name'] == '平均溫度') {
            $temp_week_map[$date_key]['temps'][] = intval($row['value']);
        }
        if ($row['element_name'] == '天氣現象') {
            $hour = date('H', strtotime($row['start_time']));
            if ($hour == '12' || empty($temp_week_map[$date_key]['main_wx'])) {
                $temp_week_map[$date_key]['main_wx'] = $row['value'];
            }
        }
    }

    foreach ($temp_week_map as $date => $info) {
        if (!empty($info['temps'])) {
            $weekly_forecasts[] = [
                'date' => $date,
                'day_name' => $week_map[date('D', strtotime($date))],
                'wx' => isset($info['main_wx']) ? $info['main_wx'] : '未知',
                'min_t' => min($info['temps']),
                'max_t' => max($info['temps'])
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>氣象查詢系統</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg-color: transparent; 
            --card-bg: rgba(30, 30, 30, 0.85); 
            --text-color: #f0f0f0;
            --text-muted: #bbb;
            --primary-color: #536dfe;
            --accent-color: #8c9eff;
            --input-bg: rgba(44, 44, 44, 0.9);
            --border-color: rgba(255, 255, 255, 0.15);
            --border-radius: 12px;
        }

        body {
            font-family: 'Noto Sans TC', sans-serif;
            background-color: #1a1a1a; 
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            line-height: 1.6;
            min-height: 100vh;
        }

        #bg-video {
            position: fixed;
            right: 0;
            bottom: 0;
            min-width: 100%;
            min-height: 100%;
            z-index: -1; 
            object-fit: cover;
            filter: brightness(0.6); 
        }

        .container { max-width: 900px; margin: 0 auto; position: relative; z-index: 1; }

        header {
            background-color: var(--card-bg);
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px); 
        }

        h1 { color: #fff; margin: 0 0 10px 0; font-size: 2rem; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        header p { color: #ddd; margin: 0; font-size: 1.1rem; }
        header strong { color: var(--accent-color); }

        .login-bar {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-bottom: 10px;
            font-size: 1rem;
            gap: 5px;
        }
        .login-box {
            background: rgba(0, 0, 0, 0.7);
            padding: 12px 25px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .login-box form {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .login-box input {
            height: 42px; 
            width: 220px; 
            padding: 0 16px;
            font-family: 'Noto Sans TC', sans-serif;
            font-size: 16px; 
            border-radius: 6px;
            border: 1px solid #444;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff; 
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .login-box input::placeholder { color: #888; }
        .login-box input:focus { outline: none; border-color: var(--primary-color); }

        .btn-login { 
            height: 42px; 
            padding: 0 25px; 
            font-family: 'Noto Sans TC', sans-serif;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            background-color: var(--primary-color);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            transition: background-color 0.2s;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .btn-login:hover { background-color: var(--accent-color); }

        .user-status { display: flex; align-items: center; gap: 15px; font-weight: 500; }
        .logout-link { 
            color: #ff5252; 
            text-decoration: none; 
            font-weight: 700;
            border: 1px solid #ff5252;
            padding: 5px 12px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .logout-link:hover { background: #ff5252; color: #fff; }

        .fav-bar {
            display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; padding: 10px;
            background: rgba(0, 0, 0, 0.6); border-radius: 8px; align-items: center;
            backdrop-filter: blur(5px);
        }
        .fav-label { font-size: 0.9em; color: #ddd; margin-right: 5px; }
        .fav-item {
            background: rgba(255, 255, 255, 0.1);
            color: #fff; padding: 5px 12px; border-radius: 20px; font-size: 0.9em;
            text-decoration: none; display: flex; align-items: center; border: 1px solid rgba(255, 255, 255, 0.2); transition: 0.2s;
        }
        .fav-item:hover { background: rgba(255, 255, 255, 0.2); border-color: var(--primary-color); }
        .fav-remove { margin-left: 8px; color: #ff5252; cursor: pointer; font-weight: bold; }
        
        .info-badges {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .aqi-banner, .uvi-banner {
            display: inline-flex; align-items: center; background: rgba(0, 0, 0, 0.6);
            padding: 8px 15px; border-radius: 50px; border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .aqi-dot, .uvi-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px; }

        .search-box {
            background: var(--card-bg); padding: 25px; border-radius: var(--border-radius); margin-bottom: 30px;
            display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .form-group { flex: 1; min-width: 200px; }
        label { display: block; margin-bottom: 10px; font-weight: 500; color: #ddd; font-size: 0.95rem; }

        select, input[type="date"], input[type="text"] {
            width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 16px;
            background-color: var(--input-bg); color: white; box-sizing: border-box; color-scheme: dark;
        }
        select:focus, input[type="date"]:focus, input[type="text"]:focus { outline: none; border-color: var(--primary-color); }

        button[type="submit"] {
            padding: 12px 30px; background-color: var(--primary-color); color: white; border: none; border-radius: 8px;
            font-size: 16px; cursor: pointer; font-weight: 500; min-width: 120px; transition: 0.2s;
        }
        button:hover { background-color: var(--accent-color); }

        .btn-star {
            background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.3); color: #ddd; font-size: 1.5rem; cursor: pointer;
            padding: 8px 15px; border-radius: 8px; min-width: auto; line-height: 1; transition: 0.3s;
            height: 45px; display: flex; align-items: center; justify-content: center;
        }
        .btn-star:hover { border-color: #ffd700; background: rgba(255, 255, 255, 0.2); }
        .btn-star.active { color: #ffd700; border-color: #ffd700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.5); }

        .time-tabs {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            margin-bottom: 15px;
            padding-bottom: 5px;
        }
        .time-tab {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: #ccc;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            white-space: nowrap;
            transition: 0.2s;
            min-width: auto;
            font-size: 0.95rem;
        }
        .time-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            font-weight: bold;
        }
        .time-tabs::-webkit-scrollbar { height: 6px; }
        .time-tabs::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }

        .forecast-list { display: flex; flex-direction: column; gap: 20px; }
        
        .weather-card {
            background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--border-radius);
            overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s;
            backdrop-filter: blur(10px);
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.4); padding: 15px 20px; border-bottom: 1px solid var(--border-color);
            font-size: 1.1rem; font-weight: 700; color: #fff; display: flex; justify-content: space-between; align-items: center;
        }
        .time-range { color: var(--accent-color); }
        
        .card-body {
            padding: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; align-items: center;
        }
        
        .info-item { text-align: center; padding: 15px 10px; background: rgba(255,255,255,0.05); border-radius: 8px; height: 100%; display: flex; flex-direction: column; justify-content: center; }
        .info-label { display: block; font-size: 0.85rem; color: #ccc; margin-bottom: 8px; }
        .info-value { display: block; font-size: 1.2rem; font-weight: bold; color: #fff; }
        .icon-large { font-size: 2.5rem; line-height: 1; margin-bottom: 5px; }

        .desc-box {
            grid-column: 1 / -1; margin-top: 10px; padding: 15px; background: rgba(83, 109, 254, 0.15);
            border-left: 4px solid var(--primary-color); border-radius: 4px; font-size: 0.95rem; color: #eee; line-height: 1.6;
        }
        
        .life-advice {
            grid-column: 1 / -1; margin-top: 5px; padding: 15px; 
            background: linear-gradient(90deg, rgba(62, 53, 73, 0.6) 0%, rgba(45, 45, 45, 0.6) 100%);
            border: 1px solid #7c4dff; border-radius: 8px; font-size: 1rem; color: #e1bee7; 
            display: flex; flex-direction: column; gap: 5px;
        }
        .advice-title { font-weight: bold; color: #b388ff; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;}

        .comments-section {
            grid-column: 1 / -1; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);
        }
        .comment-toggle { cursor: pointer; color: #ccc; font-size: 0.9rem; margin-bottom: 10px; display: inline-block; }
        .comment-toggle:hover { color: white; }
        .comment-list { max-height: 200px; overflow-y: auto; margin-bottom: 10px; padding-right: 5px; }
        .comment-item { background: rgba(0, 0, 0, 0.3); padding: 8px; border-radius: 5px; margin-bottom: 8px; font-size: 0.9rem; color: #ddd; }
        .comment-time { color: #999; font-size: 0.8em; float: right; margin-left: 10px;}
        .comment-form { display: flex; gap: 8px; margin-top: 10px; }
        .comment-input-content { flex: 1; padding: 8px; border-radius: 5px; border: 1px solid #555; background: rgba(0,0,0,0.5); color: white; }
        .comment-btn { padding: 8px 15px; border-radius: 5px; min-width: auto; height: auto; font-size: 0.9rem; }

        .val-temp { color: #ff8a80; }
        .val-rain { color: #80d8ff; }
        .val-wx { color: #ffe082; }
        .val-rh { color: #a7ffeb; }
        .val-ws { color: #cfd8dc; }
        
        .chart-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
            height: 350px;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .weekly-container {
            margin-bottom: 30px;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            padding: 20px;
            backdrop-filter: blur(10px);
        }
        .weekly-title { font-size: 1.2rem; font-weight: bold; margin-bottom: 15px; color: var(--primary-color); }
        .weekly-scroll { display: flex; gap: 15px; overflow-x: auto; padding-bottom: 10px; }
        .weekly-scroll::-webkit-scrollbar { height: 8px; }
        .weekly-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        .weekly-card {
            min-width: 100px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 15px 10px;
            text-align: center;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .w-day { font-weight: bold; margin-bottom: 5px; color: #fff; }
        .w-date { font-size: 0.8em; color: #aaa; margin-bottom: 10px; }
        .w-icon { font-size: 2rem; margin: 10px 0; }
        .w-temp { font-weight: bold; color: #ff8a80; }
        .w-temp-low { color: #80d8ff; font-size: 0.9em; }
        
        .empty-state {
            text-align: center; padding: 60px 20px; color: #ddd; font-size: 1.1rem;
            background: var(--card-bg); border-radius: 8px; border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        @media (max-width: 600px) {
            .container { padding: 10px; }
            .login-box { flex-direction: column; gap: 10px; }
            .login-box form { flex-direction: column; width: 100%; }
            .login-box input { width: 100%; }
            .search-box { flex-direction: column; gap: 15px; }
            .form-group, button { width: 100%; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 5px; }
            .card-body { grid-template-columns: repeat(2, 1fr); }
            .icon-large { font-size: 2rem; }
            .chart-container { height: 280px; }
            .comment-form { flex-direction: column; }
            .comment-input-content, .comment-btn { width: 100%; }
        }
    </style>
    
    <script>
        let dbFavs = <?= json_encode($user_favs) ?>;

        function getFavorites() {
            <?php if(isset($_SESSION['user_id'])): ?>
                return dbFavs;
            <?php else: ?>
                const favs = localStorage.getItem('weatherFavs');
                return favs ? JSON.parse(favs) : [];
            <?php endif; ?>
        }

        function toggleFavorite(id, city, name) {
            if (!id) return; 
            
            <?php if(!isset($_SESSION['user_id'])): ?>
                let favs = getFavorites();
                const index = favs.findIndex(item => item.id == id);
                if (index === -1) {
                    favs.push({ id: id, city: city, name: name });
                    alert('已加入最愛！(提醒：登入後可永久儲存)');
                } else {
                    favs.splice(index, 1);
                    alert('已從最愛移除。');
                }
                localStorage.setItem('weatherFavs', JSON.stringify(favs));
                renderFavorites();
                checkStarStatus();
            <?php else: ?>
                const formData = new FormData();
                formData.append('action', 'toggle_fav');
                formData.append('loc_id', id);

                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(res => {
                    if (res === 'added') {
                        dbFavs.push({ id: id, city: city, name: name });
                        alert('已儲存至您的帳號最愛！');
                    } else if (res === 'removed') {
                        dbFavs = dbFavs.filter(item => item.id != id);
                        alert('已從您的帳號最愛移除。');
                    }
                    renderFavorites();
                    checkStarStatus();
                });
            <?php endif; ?>
        }

        function removeFavorite(e, id) {
            e.preventDefault(); e.stopPropagation(); 
            const favs = getFavorites();
            const target = favs.find(f => f.id == id);
            if(target) toggleFavorite(id, target.city, target.name);
        }

        function renderFavorites() {
            const list = document.getElementById('fav-list');
            const favs = getFavorites();
            list.innerHTML = '';
            if (favs.length === 0) {
                document.getElementById('fav-container').style.display = 'none';
                return;
            }
            document.getElementById('fav-container').style.display = 'flex';
            favs.forEach(fav => {
                const a = document.createElement('a');
                a.href = `?city=${fav.city}&location_id=${fav.id}`;
                a.className = 'fav-item';
                a.innerHTML = `${fav.city} ${fav.name} <span class="fav-remove" onclick="removeFavorite(event, '${fav.id}')">&times;</span>`;
                list.appendChild(a);
            });
        }

        function checkStarStatus() {
            const btn = document.getElementById('btn-star');
            if (!btn) return;
            const currentId = btn.getAttribute('data-id');
            const favs = getFavorites();
            const isFav = favs.some(item => item.id == currentId);
            if (isFav) {
                btn.classList.add('active'); btn.innerHTML = '★';
            } else {
                btn.classList.remove('active'); btn.innerHTML = '☆';
            }
        }

        function autoSubmit() {
            document.getElementById('searchForm').submit();
        }

        function switchTime(ts) {
            const cards = document.querySelectorAll('.weather-card-wrapper');
            cards.forEach(card => card.style.display = 'none');
            const targetCard = document.getElementById('card-' + ts);
            if (targetCard) targetCard.style.display = 'block';
            const tabs = document.querySelectorAll('.time-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            const activeTab = document.getElementById('tab-' + ts);
            if (activeTab) activeTab.classList.add('active');
        }

        function initChart() {
            const ctx = document.getElementById('tempChart');
            if (!ctx) return;
            const labels = <?= json_encode($chart_labels) ?>;
            const dataTemp = <?= json_encode($chart_data_temp) ?>;
            const dataRH = <?= json_encode($chart_data_rh) ?>;
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: '溫度 (°C)', data: dataTemp, borderColor: '#ff8a80', backgroundColor: '#ff8a80', borderWidth: 3, yAxisID: 'y', tension: 0.4 },
                        { label: '濕度 (%)', data: dataRH, borderColor: '#4fc3f7', backgroundColor: 'rgba(79, 195, 247, 0.15)', borderWidth: 2, fill: true, yAxisID: 'y1', tension: 0.4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { labels: { color: '#e0e0e0' } }, title: { display: true, text: '前後 24 小時趨勢圖', color: '#aaa' } },
                    scales: {
                        x: { ticks: { color: '#ccc' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
                        y: { type: 'linear', display: true, position: 'left', title: { display: true, text: '溫度', color: '#ff8a80' }, ticks: { color: '#ff8a80' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
                        y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: '濕度', color: '#4fc3f7' }, ticks: { color: '#4fc3f7' }, grid: { drawOnChartArea: false }, min: 0, max: 100 }
                    }
                }
            });
        }

        function toggleComments(btn) {
            const section = btn.nextElementSibling;
            if (section.style.display === 'none' || section.style.display === '') {
                section.style.display = 'block';
                btn.innerText = '🔼 收起留言';
            } else {
                section.style.display = 'none';
                btn.innerText = '💬 查看/新增留言';
            }
        }

        // --- 新增：切換登入/註冊表單的 JS 函數 ---
        function switchAuthMode(mode) {
            const loginArea = document.getElementById('login-form-area');
            const regArea = document.getElementById('register-form-area');
            if (mode === 'login') {
                loginArea.style.display = 'flex';
                regArea.style.display = 'none';
            } else {
                loginArea.style.display = 'none';
                regArea.style.display = 'flex';
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            renderFavorites();
            checkStarStatus();
            initChart(); 
        });
    </script>
</head>
<body>

<video autoplay muted loop id="bg-video">
    <source src="videos/<?= $current_bg_video ?>" type="video/mp4">
</video>

<div class="container">
    <div class="login-bar">
        <div class="login-box" style="flex-direction: column; align-items: flex-start; gap: 10px; min-width: 320px;">
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="user-status">
                    <span>歡迎，<strong><?= htmlspecialchars($_SESSION['username']) ?></strong></span>
                    <?php if ($_SESSION['role'] == 0): ?>
                        <a href="import_api.php" style="color:#ffeb3b; text-decoration:none; font-weight:700;">[後台管理]</a>
                    <?php endif; ?>
                    <a href="?logout=1" class="logout-link">登出</a>
                </div>
            <?php else: ?>
                <div style="width: 100%; display: flex; align-items: center; gap: 10px;">
                    <label style="margin: 0; font-size: 0.9rem; white-space: nowrap;">操作類型：</label>
                    <select id="auth-mode-select" onchange="switchAuthMode(this.value)" style="flex: 1; height: 35px; padding: 0 10px; font-size: 0.9rem;">
                        <option value="login" <?= (!isset($_POST['register_action'])) ? 'selected' : '' ?>>登入帳號</option>
                        <option value="register" <?= (isset($_POST['register_action'])) ? 'selected' : '' ?>>註冊帳號</option>
                    </select>
                </div>

                <div id="login-form-area" style="display: <?= (!isset($_POST['register_action'])) ? 'flex' : 'none' ?>; width: 100%; flex-direction: column; gap: 8px;">
                    <form method="POST" action="" style="width: 100%;">
                        <input type="hidden" name="login_action" value="1">
                        <div style="display: flex; gap: 10px;">
                            <input type="text" name="username" placeholder="帳號" required style="width: 110px; height: 38px;">
                            <input type="password" name="password" placeholder="密碼" required style="width: 110px; height: 38px;">
                            <button type="submit" class="btn-login" style="height: 38px; padding: 0 15px;">登入</button>
                        </div>
                    </form>
                    <?php if ($login_error): ?>
                        <span style="color:#ff5252; font-size:0.85rem; font-weight:700;"><?= $login_error ?></span>
                    <?php endif; ?>
                </div>

                <div id="register-form-area" style="display: <?= (isset($_POST['register_action'])) ? 'flex' : 'none' ?>; width: 100%; flex-direction: column; gap: 8px;">
                    <form method="POST" action="" style="width: 100%;">
                        <input type="hidden" name="register_action" value="1">
                        <div style="display: flex; gap: 10px;">
                            <input type="text" name="reg_username" placeholder="註冊帳號" required style="width: 110px; height: 38px;">
                            <input type="password" name="reg_password" placeholder="註冊密碼" required style="width: 110px; height: 38px;">
                            <button type="submit" class="btn-login" style="height: 38px; padding: 0 15px; background-color: #4caf50;">註冊</button>
                        </div>
                    </form>
                    <?php if ($reg_error): ?>
                        <span style="color:#ff5252; font-size:0.85rem; font-weight:700;"><?= $reg_error ?></span>
                    <?php endif; ?>
                    <?php if ($reg_success): ?>
                        <span style="color:#4caf50; font-size:0.85rem; font-weight:700;"><?= $reg_success ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <header>
        <h1>氣象查詢系統</h1>
        <?php if($location_name_display): ?>
            <p>
                位置：<strong><?= htmlspecialchars($location_name_display) ?></strong> 
                &nbsp;|&nbsp; 
                日期：<strong><?= htmlspecialchars($selected_date) ?></strong>
            </p>
        <?php endif; ?>

        <div class="info-badges">
            <?php if($aqi_info): ?>
                <div class="aqi-banner">
                    <span class="aqi-dot" style="background-color: <?= getAqiColor($aqi_info['val']) ?>;"></span>
                    <span>
                        AQI：
                        <strong style="color: <?= getAqiColor($aqi_info['val']) ?>;">
                            <?= $aqi_info['val'] ?> (<?= $aqi_info['status'] ?>)
                        </strong>
                    </span>
                </div>
            <?php endif; ?>

            <?php if($header_uvi !== null): ?>
                <div class="uvi-banner">
                    <span class="uvi-dot" style="background-color: <?= getUviColor($header_uvi) ?>;"></span>
                    <span>
                        紫外線：
                        <strong style="color: <?= getUviColor($header_uvi) ?>;">
                            <?= $header_uvi ?>
                        </strong>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div id="fav-container" class="fav-bar" style="display: none;">
        <span class="fav-label">⭐ 我的最愛：</span>
        <div id="fav-list" style="display: flex; gap: 10px; flex-wrap: wrap;"></div>
    </div>

    <form method="GET" action="" class="search-box" id="searchForm">
        <div class="form-group">
            <label for="city">1. 選擇縣市</label>
            <select name="city" id="city" onchange="autoSubmit()">
                <option value="">-- 請先選擇縣市 --</option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= $city ?>" <?= $selected_city == $city ? 'selected' : '' ?>><?= htmlspecialchars($city) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="location">2. 選擇鄉鎮市區</label>
            <select name="location_id" id="location">
                <option value="">-- 請選擇地區 --</option>
                <?php foreach ($districts as $dist): ?>
                    <option value="<?= $dist['id'] ?>" <?= $selected_loc == $dist['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dist['location_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="search_date">3. 選擇日期</label>
            <input type="date" name="search_date" id="search_date" value="<?= htmlspecialchars($selected_date) ?>" required>
        </div>

        <button type="submit">查詢天氣</button>
        
        <?php if($selected_loc): ?>
            <button type="button" id="btn-star" class="btn-star" 
                data-id="<?= $selected_loc ?>" 
                onclick="toggleFavorite('<?= $selected_loc ?>', '<?= $selected_city ?>', '<?= $current_loc_name_only ?>')"
                title="加入最愛">
                ☆
            </button>
        <?php endif; ?>
    </form>

    <?php if (!empty($grouped_forecasts)): ?>
        
        <div class="chart-container">
            <canvas id="tempChart"></canvas>
        </div>

        <?php if (!empty($weekly_forecasts)): ?>
            <div class="weekly-container">
                <div class="weekly-title">📅 未來一週天氣概況</div>
                <div class="weekly-scroll">
                    <?php foreach ($weekly_forecasts as $w_data): ?>
                        <div class="weekly-card">
                            <div class="w-day"><?= $w_data['day_name'] ?></div>
                            <div class="w-date"><?= date('m/d', strtotime($w_data['date'])) ?></div>
                            <div class="w-icon"><?= getWeatherIcon($w_data['wx']) ?></div>
                            <div class="w-temp"><?= $w_data['max_t'] ?>°</div>
                            <div class="w-temp-low"><?= $w_data['min_t'] ?>°</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php 
            $active_ts = 0;
            $current_time = time();
            foreach ($grouped_forecasts as $ts => $data) {
                if ($active_ts === 0) $active_ts = $ts; 
                if ($selected_date == date('Y-m-d') && $current_time >= strtotime($data['start']) && $current_time < strtotime($data['end'])) {
                    $active_ts = $ts;
                    break;
                }
            }
        ?>
        <div class="time-tabs">
            <?php foreach ($grouped_forecasts as $ts => $data): ?>
                <button id="tab-<?= $ts ?>" class="time-tab <?= ($ts == $active_ts) ? 'active' : ''; ?>" onclick="switchTime('<?= $ts ?>')"><?= date('H:i', strtotime($data['start'])) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="forecast-list">
            <?php foreach ($grouped_forecasts as $ts => $data): ?>
                <?php 
                    $pop = ($data['pop'] == ' ' || $data['pop'] == '-') ? '0' : $data['pop'];
                    $wx_icon = getWeatherIcon($data['wx']);
                    $temp = intval($data['t']);
                    $display_style = ($ts == $active_ts) ? 'block' : 'none';
                    $stmt_comments = $pdo->prepare("SELECT * FROM weather_comments WHERE location_id = ? AND target_time = ? ORDER BY created_at DESC");
                    $stmt_comments->execute([$selected_loc, $data['start']]);
                    $comments = $stmt_comments->fetchAll();

                    $clothes = [];
                    $tips = [];
                    $uvi = ($data['uvi'] == '-' || $data['uvi'] == ' ') ? 0 : intval($data['uvi']);
                    $pop_val = intval($pop);
                    $ws = ($data['ws'] == '-' || $data['ws'] == ' ') ? 0 : floatval($data['ws']);
                    $rh = ($data['rh'] == '-' || $data['rh'] == ' ') ? 0 : intval($data['rh']);

                    if ($temp < 15) {
                        $clothes[] = "羽絨外套"; $clothes[] = "毛衣"; $clothes[] = "圍巾";
                    } elseif ($temp >= 15 && $temp < 20) {
                        $clothes[] = "夾克/風衣"; $clothes[] = "長袖上衣";
                    } elseif ($temp >= 20 && $temp < 25) {
                        $clothes[] = "薄長袖"; $clothes[] = "襯衫";
                    } elseif ($temp >= 25 && $temp < 30) {
                        $clothes[] = "短袖"; $clothes[] = "透氣衣物";
                    } else {
                        $clothes[] = "背心"; $clothes[] = "短褲";
                        $tips[] = "天氣炎熱，請多補充水分";
                    }

                    if ($uvi >= 0 && $uvi <= 2) {
                    } elseif ($uvi >= 3 && $uvi <= 5) {
                        $clothes[] = "帽子"; $clothes[] = "太陽眼鏡"; $tips[] = "請使用防曬液";
                    } elseif ($uvi >= 6 && $uvi <= 7) {
                        $clothes[] = "帽子"; $clothes[] = "太陽眼鏡"; $clothes[] = "陽傘"; 
                        $tips[] = "使用防曬液，盡量待在陰涼處";
                    } elseif ($uvi >= 8 && $uvi <= 10) {
                        $clothes[] = "帽子"; $clothes[] = "太陽眼鏡"; $clothes[] = "陽傘"; $clothes[] = "長袖衣物";
                        $tips[] = "使用防曬液，上午10時至下午2時最好不外出";
                    } elseif ($uvi >= 11) {
                        $clothes[] = "防曬衣物"; 
                        $tips[] = "紫外線危險級，需嚴密防護，避免長時間戶外活動";
                    }

                    if (strpos($data['wx'], '雨') !== false) $tips[] = "攜帶雨傘 (預報有雨)";
                    if ($pop_val >= 40) $tips[] = "攜帶雨具 (降雨機機率高)";
                    if ($ws >= 4) $clothes[] = "防風外套";
                    if ($rh > 80 && $temp > 28) $tips[] = "體感悶熱，建議穿著排汗衣物";
                    if ($aqi_info && $aqi_info['val'] > 100) $clothes[] = "口罩 (空氣不佳)";

                    $clothes_str = implode('、', array_unique($clothes));
                    $tips_str = implode('；', array_unique($tips));
                ?>
                <div id="card-<?= $ts ?>" class="weather-card-wrapper" style="display: <?= $display_style ?>;">
                    <div class="weather-card">
                        <div class="card-header"><span class="time-range"><?= date('H:i', strtotime($data['start'])) ?> ~ <?= date('H:i', strtotime($data['end'])) ?></span></div>
                        <div class="card-body">
                            <div class="info-item"><div class="icon-large"><?= $wx_icon ?></div><span class="info-value val-wx"><?= htmlspecialchars($data['wx']) ?></span></div>
                            <div class="info-item"><span class="info-label">平均溫度</span><span class="info-value val-temp"><?= htmlspecialchars($data['t']) ?>°C</span></div>
                            <div class="info-item"><span class="info-label">降雨機率</span><span class="info-value val-rain"><?= htmlspecialchars($pop) ?>%</span></div>
                            
                            <div class="life-advice">
                                <div class="advice-title">👕 智慧穿衣與生活建議</div>
                                <div><strong>建議：</strong><?= $clothes_str ?></div>
                                <?php if($tips_str): ?>
                                    <div style="margin-top:2px;"><strong>提醒：</strong><?= $tips_str ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if(!empty($data['desc'])): ?><div class="desc-box"><?= htmlspecialchars($data['desc']) ?></div><?php endif; ?>
                            
                            <div class="comments-section">
                                <div class="comment-toggle" onclick="toggleComments(this)">💬 查看/新增留言 (<?= count($comments) ?>)</div>
                                <div style="display: none;">
                                    <div class="comment-list"><?php foreach($comments as $cmt): ?><div class="comment-item"><strong><?= htmlspecialchars($cmt['user_name']) ?>：</strong><?= htmlspecialchars($cmt['content']) ?></div><?php endforeach; ?></div>
                                    <form method="POST" class="comment-form">
                                        <input type="hidden" name="action" value="add_comment"><input type="hidden" name="loc_id" value="<?= $selected_loc ?>"><input type="hidden" name="target_time" value="<?= $data['start'] ?>"><input type="hidden" name="current_city" value="<?= $selected_city ?>"><input type="hidden" name="current_date" value="<?= $selected_date ?>">
                                        <input type="text" name="content" placeholder="分享天氣..." class="comment-input-content" required><button type="submit" class="comment-btn">送出</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($selected_loc != ''): ?>
        <div class="empty-state"><p>找不到資料。</p></div>
    <?php else: ?>
        <div class="empty-state"><p>請選擇縣市與地區查詢。</p></div>
    <?php endif; ?>
</div>
</body>
</html>