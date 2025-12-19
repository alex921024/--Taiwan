# 🌤️ 台灣全方位氣象查詢系統 (Weather Query System)

這是一個基於 PHP 與 MySQL 開發的氣象資訊平台。系統整合了台灣中央氣象署（CWA）與環境部（MOENV）的開放資料 API，提供包含即時天氣預報、空氣品質（AQI）、紫外線指數（UVI）以及穿衣建議等功能。

## 🌟 核心功能

* [cite_start]**多維度天氣資訊**：提供平均溫度、降雨機率、相對濕度、風速及紫外線指數 [cite: 1, 2]。
* [cite_start]**動態視覺體驗**：網頁背景影片會根據當前天氣現象（如：晴、雨、雲、雷）自動切換 。
* [cite_start]**數據可視化**：整合 **Chart.js** 繪製未來 24 小時的溫度與濕度趨勢圖表 。
* [cite_start]**智慧生活建議**：系統根據氣溫與紫外線強度，自動生成「智慧穿衣建議」與「生活提醒」 。
* [cite_start]**空氣品質監測**：即時顯示縣市平均 AQI 指標，並以顏色區分等級 [cite: 1, 2]。
* **會員系統與收藏**：
    * [cite_start]**註冊與登入**：支援使用者帳號管理，密碼採用 SHA256 加密儲存 [cite: 1, 3]。
    * [cite_start]**我的最愛**：登入後可永久收藏常用地區；未登入者則暫存於 Browser LocalStorage [cite: 1, 3]。
* [cite_start]**社群互動**：使用者可針對特定預報時段進行留言與經驗分享 [cite: 1, 3]。
* [cite_start]**管理員後台**：專屬 `import_api.php` 頁面，具備一鍵更新全國天氣與 AQI 測站資料的功能 。

## 🛠️ 技術棧

* [cite_start]**後端語言**: PHP (PDO 資料庫連線技術) [cite: 1, 2]
* [cite_start]**資料庫**: MySQL / MariaDB [cite: 3]
* [cite_start]**前端技術**: HTML5, CSS3 (具備 RWD 響應式設計), JavaScript 
* [cite_start]**外部庫**: Chart.js (圖表呈現), Google Fonts (Noto Sans TC) 
* [cite_start]**資料來源**: [中央氣象署](https://opendata.cwa.gov.tw/)、[環境部環境資料開放平臺](https://data.moenv.gov.tw/) 

## 📂 資料庫結構說明

[cite_start]系統共包含 6 張核心資料表 [cite: 3]：

1.  **`locations`**: 儲存縣市、鄉鎮名稱及經緯度座標。
2.  **`forecasts`**: 儲存各類氣象因子（溫度、降雨等）的時段預報。
3.  **`aqi_data`**: 儲存全國空氣品質測站數據。
4.  **`users`**: 儲存使用者帳號資訊（role 0 為管理員，1 為一般使用者）。
5.  **`user_favorites`**: 紀錄使用者收藏的地區關聯。
6.  **`weather_comments`**: 儲存天氣預報時段下的使用者留言。

## 🚀 快速開始

### 1. 建立資料庫
請在 MySQL 中執行以下指令：
```sql
CREATE DATABASE weather_system DEFAULT CHARSET=utf8mb4;
