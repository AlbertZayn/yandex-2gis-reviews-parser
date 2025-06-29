# yandex-2gis-review-parser

Агент для 1С‑Bitrix, который периодически парсит и сохраняет отзывы из Яндекс.Карт и 2GIS в инфоблок.  
Позволяет автоматически выгружать количество отзывов, общий рейтинг и сами отзывы с фотографиями в разделы инфоблока.

## ⚙️ Установка и подключение

1. Скопируйте папку `local/php_interface/` в корень вашего сайта Битрикс.
2. В файле `local/php_interface/agents.php` подключите агента:
   ```php
   <?php
   require_once $_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/agents/yandex2gisreviewsagent.php";

## 🛠️ Конфигурация

yandex2gisreviewsagent.php:
```php
$sYandexUrl = "https://yandex.ru/maps/org/<ВАШЕ_ОРГАНИЗАЦИЯ>/<ID>/reviews/";
```
DoubleGisReviewParserJson.php:
```php
private const API_URL = "https://public-api.reviews.2gis.com/2.0/branches/<ВАШ_BRANCH_ID>/reviews";
private const API_KEY = "<ВАШ_PUBLIC_API_KEY>";
$iIblockId       = <ID_ВАШЕГО_ИНФОБЛОКА>;
$iYandexSectionId = <ID_РАЗДЕЛА_ДЛЯ_YANDEX>;
$i2GisSectionId   = <ID_РАЗДЕЛА_ДЛЯ_2GIS>;
```
