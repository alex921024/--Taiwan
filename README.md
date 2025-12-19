
# 🌦️ Taiwan Weather Forecast System (台灣氣象查詢系統)

這是一個基於 PHP 與 MySQL 開發的全功能氣象查詢平台。系統整合了台灣**中央氣象署 (CWA)** 與 **環境部 (MoENV)** 的開放資料 API，提供即時的天氣預報、空氣品質 (AQI) 監測，並結合動態視覺效果與使用者社群功能。

## ✨ 特色功能 (Features)

### 🖥️ 前台使用者介面 (Frontend)
**動態天氣背景**：根據當下天氣狀況 (晴、雨、雷、雪等) 自動切換背景影片 。
**視覺化圖表**：使用 `Chart.js` 繪製前後 24 小時的溫度與濕度趨勢圖 。
**詳細生活指數**：提供紫外線 (UVI) 警示，並根據溫度與天氣給予智慧穿衣建議 (如：洋蔥式穿搭、攜帶雨具提醒) 。
**未來一週預報**：卡片式滑動介面，瀏覽未來 7 天的高低溫與天氣概況 。
* **會員系統**：
* 註冊/登入 (密碼採 SHA-256 加密) 。
* 我的最愛：會員可將常駐地區加入資料庫收藏；訪客則使用 LocalStorage 暫存 。
* 留言互動：針對特定時段的預報進行留言討論 。

### ⚙️ 後台管理系統 (Admin Dashboard)

* **權限控管**：僅限管理員 (`role=0`) 進入後台 。
* **資料同步**：
* **天氣資料**：串接氣象署 API (F-D0047 系列)，支援全台各縣市鄉鎮區的 36 小時逐 3 小時預報 。
* **空氣品質**：一鍵更新全台測站 AQI 數據與座標 。

## 🛠️ 技術棧 (Tech Stack)

* **Frontend**: HTML5, CSS3 (Flexbox/Grid), JavaScript (Vanilla), Chart.js
* **Backend**: Native PHP (7.4+)
* **Database**: MySQL / MariaDB
**Server**: Apache (支援 HTTPS 與 Rewrite) 


* **External APIs**:
* [CWA 中央氣象署開放資料平台](https://opendata.cwa.gov.tw/)
* [MoENV 環境部開放資料平台](https://data.moenv.gov.tw/)



## 🚀 安裝與執行 (Installation)

### 1. 環境需求

* Web Server (XAMPP, WAMP, or Apache/Nginx)
* PHP 7.4 或更高版本
* MySQL 資料庫
* 支援 SSL (建議啟用 HTTPS 以確保定位與 API 安全) 



### 2. 專案設定

將專案複製到您的網頁根目錄 (例如 `htdocs` 或 `www`)：

```bash
git clone https://github.com/yourusername/weather-system.git

```

**⚠️ 注意：靜態資源設置**
專案依賴本地影片檔案，請在根目錄建立 `videos/` 資料夾，並放入以下 MP4 檔案以對應天氣現象 ：

* `sunny.mp4`, `cloudy.mp4`, `rain.mp4`, `thunder.mp4`, `snow.mp4`, `fog.mp4`, `partly_cloudy.mp4`

### 3. 資料庫設定 (Database Setup)

請在 MySQL 中執行以下 SQL 指令以建立資料庫與資料表結構 ：

```sql
CREATE DATABASE weather_system DEFAULT CHARSET=utf8mb4;
USE weather_system;

-- 地點資料表
CREATE TABLE `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `city_name` varchar(20) NOT NULL DEFAULT '',
  `location_name` varchar(50) NOT NULL,
  `geocode` varchar(20) DEFAULT NULL,
  `lat` varchar(20) DEFAULT NULL,
  `lon` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 預報資料表
CREATE TABLE `forecasts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `element_name` varchar(50) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `value` text NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_location` (`location_id`),
  KEY `idx_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 空氣品質資料表
CREATE TABLE aqi_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    county VARCHAR(20),
    sitename VARCHAR(20),
    aqi INT,
    status VARCHAR(20),
    longitude VARCHAR(20),
    latitude VARCHAR(20),
    publishtime DATETIME
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 使用者資料表
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `role` int(1) NOT NULL DEFAULT 1 COMMENT '0:管理者, 1:一般使用者',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 使用者收藏表
CREATE TABLE `user_favorites` (
  `user_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`, `location_id`),
  CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 留言板資料表
CREATE TABLE `weather_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `target_time` datetime NOT NULL,
  `user_name` varchar(50) NOT NULL,
  `content` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

```

### 4. 設定資料庫連線

打開 `index.php` 與 `import_api.php`，確保資料庫連線資訊正確：

```php
$host = 'localhost';
$db   = 'weather_system';
$user = 'root'; // 您的資料庫帳號
$pass = '';     // 您的資料庫密碼

```

### 5. API Key 設定

本系統使用 CWA 氣象開放資料，請在 `import_api.php` 中確認或替換您的 API Key：

```php
// import_api.php 第 47 行
$apiUrl = "https://opendata.cwa.gov.tw/api/v1/rest/datastore/{$dataid}?Authorization=YOUR_CWA_API_KEY&format=JSON";

```

## 📖 使用說明 (Usage)

### 首次初始化 (管理員)

1. 進入首頁註冊一個新帳號。
2. 進入資料庫管理工具 (如 phpMyAdmin)，執行 SQL 將該帳號升級為管理員：
```sql
UPDATE users SET role = 0 WHERE username = '您的帳號';

```


3. 以管理員身分登入，點擊上方導覽列出現的 **[後台管理]** 連結 (指向 `import_api.php`) 。


4. 在後台：
* 選擇縣市並點擊「下載並匯入天氣資料」以初始化地區與預報數據。
* 點擊「更新全國 AQI」以抓取空氣品質資料。



### 一般使用者

1. 在首頁選擇「縣市」與「鄉鎮市區」。
2. 點擊查詢即可看到詳細天氣資訊、穿衣建議與圖表。
3. 登入後點擊「☆」可將地點加入最愛。

## 📂 檔案結構

```
/
├── index.php           # 主頁面 (查詢、顯示、登入系統)
├── import_api.php      # 後台 API 串接與資料更新介面
├── weather_system.txt  # 原始資料庫 Schema 與 Server Config
└── videos/             # (需自行建立) 天氣背景影片存放區

```

## 📜 License

本專案為學術/練習用途，使用之氣象資料版權歸 **中央氣象署** 與 **環境部** 所有。

### 📝 TODO / 未來展望

* [ ] 增加「定位功能」自動抓取最近測站天氣。
* [ ] 優化手機版介面的 RWD 體驗。
* [ ] 實作密碼忘記/重設功能 (SMTP Mailer)。
