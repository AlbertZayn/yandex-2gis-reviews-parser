<?php

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

require_once $_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/lib/review-parser/ContentParser.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/lib/review-parser/YandexReviewParser.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/lib/review-parser/DoubleGisReviewParserJson.php";

function downloadImageFile(string $sUrl)
{
    // Скачиваем содержимое
    $oImageData = @file_get_contents($sUrl);
    if($oImageData === false){
        AddMessage2Log("Не удалось скачать файл по URL: {$sUrl}", "Yandex2GisReviewsAgent");
        return false;
    }

    // Определяем расширение по Content-Type
    $sHeaders = get_headers($sUrl, 1);
    $sContentType = "";
    if(isset($sHeaders["Content-Type"])){
        $sContentType = is_array($sHeaders["Content-Type"]) ? $sHeaders["Content-Type"][0] : $sHeaders["Content-Type"];
    }
    $sExtension = "jpg";
    if(str_contains($sContentType, "png")){
        $sExtension = "png";
    }elseif(str_contains($sContentType, "gif")){
        $sExtension = "gif";
    }elseif(str_contains($sContentType, "webp")){
        $sExtension = "webp";
    }

    // Создаём временный файл в /bitrix/tmp
    $sTmpDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/tmp";
    if(!is_dir($sTmpDir)){
        @mkdir($sTmpDir, 0775, true);
    }
    $sTmpFileName = uniqid("yandex_img_") . "." . $sExtension;
    $sTmpFilePath = $sTmpDir . "/" . $sTmpFileName;
    $arWritten = @file_put_contents($sTmpFilePath, $oImageData);
    if($arWritten === false){
        AddMessage2Log("Не удалось записать временный файл: {$sTmpFilePath}", "Yandex2GisReviewsAgent");
        return false;
    }

    // массив для Bitrix
    $arFile = array(
        "tmp_name" => $sTmpFilePath,
        "name" => "yandex_review_image." . $sExtension,
        "type" => $sContentType ?: "image/jpeg",
        "size" => filesize($sTmpFilePath),
    );

    return $arFile;
}

function Yandex2GisReviewsAgent()
{
    if(!Loader::includeModule("iblock")){
        return "Yandex2GisReviewsAgent();";
    }

    $iIblockId       = <ID_ВАШЕГО_ИНФОБЛОКА>;
    $iYandexSectionId = <ID_РАЗДЕЛА_ДЛЯ_YANDEX>;
    $i2GisSectionId   = <ID_РАЗДЕЛА_ДЛЯ_2GIS>;
    $sYandexUrl = "https://yandex.ru/maps/org/<ВАША_ОРГАНИЗАЦИЯ>/<ID>/reviews/";

    // Парсинг данных Яндекс
    $iYandexAmountReviews = 0;
    $iYandexFullRating = 0.0;
    $arYandexReviews = array();
    try{
        $oYParser = new Mendeleev\Lib\ReviewParser\ContentParser($sYandexUrl);
        $oYDom = $oYParser->getContent();
        $oYandexParser = new Mendeleev\Lib\ReviewParser\YandexReviewParser($oYDom);
        $sYAmount = $oYandexParser->getAmountReviews();
        preg_match('/(\d+)/', $sYAmount, $arMatches);
        $iYandexAmountReviews = isset($arMatches[1]) ? (int)$arMatches[1] : 0;
        try{
            $arYRatingSummary = $oYandexParser->parseRatingSummary();
        }catch(TypeError $e){
            $arYRatingSummary = array("SUMMARY_RATING" => array("FULL_RATING" => 0.0));
        }
        $iYandexFullRating = isset($arYRatingSummary["SUMMARY_RATING"]["FULL_RATING"]) ? (float)$arYRatingSummary["SUMMARY_RATING"]["FULL_RATING"] : 0.0;
        $arYandexReviews = $oYandexParser->parseReviews();
        // Удаляем первый отзыв так как он аккумулирует все фотографии
        array_shift($arYandexReviews);
    }catch(Exception $oException){
        AddMessage2Log("Ошибка парсинга Яндекс: " . $oException->getMessage(), "Yandex2GisReviewsAgent");
    }

    // Парсинг данных 2GIS
    $iGisAmountReviews = 0;
    $iGisFullRating = 0.0;
    $arGisReviews = array();
    try{
        $oGisParser = new Mendeleev\Lib\ReviewParser\DoubleGisReviewParserJson();
        $iGisAmount = $oGisParser->getAmountReviews();
        $iGisAmountReviews = (int)$iGisAmount;
        $arGRatingSummary = $oGisParser->parseRatingSummary();
        $iGisFullRating = isset($arGRatingSummary["FULL_RATING"]) ? (float)$arGRatingSummary["FULL_RATING"] : 0.0;
        $arGisReviews = $oGisParser->parseReviews();
    }catch(Exception $oException){
        AddMessage2Log("Ошибка парсинга 2GIS: " . $oException->getMessage(), "Yandex2GisReviewsAgent");
    }

    // Обновление пользовательских полей разделов
    $oSection = new CIBlockSection;
    $arYSectionFields = array(
        "UF_AMOUNT_REVIEWS" => $iYandexAmountReviews,
        "UF_FULL_RATING" => $iYandexFullRating
    );
    $oSection->Update($iYandexSectionId, $arYSectionFields);

    $arGSectionFields = array(
        "UF_AMOUNT_REVIEWS" => $iGisAmountReviews,
        "UF_FULL_RATING" => $iGisFullRating
    );
    $oSection->Update($i2GisSectionId, $arGSectionFields);

    // Функция для обработки массива отзывов
    function ProcessReviews(array $arReviews, $iSectionId, $iIblockId)
    {
        foreach($arReviews as $iIndex => $arReview){
            $iSort = $iIndex + 1;
            $sAuthorName = $arReview["AUTHOR"]["name"] ?? "";
            $sPreviewText = $arReview["TEXT"] ?? "";
            $iRating = isset($arReview["RATING"]) ? (int)$arReview["RATING"] : 0;
            $sAvatarUrl = $arReview["AUTHOR"]["avatar"] ?? "";
            $arGallery = isset($arReview["GALLERY"]) && is_array($arReview["GALLERY"]) ? $arReview["GALLERY"] : array();

            // Обработка даты публикации
            $sPublishDate = "";
            $oDateTime = null;

            try{
                if(isset($arReview["DATE"])){
                    $sDate = trim($arReview["DATE"]);

                    $arMonths = array(
                        "января" => "01", "февраля" => "02", "марта" => "03",
                        "апреля" => "04", "мая" => "05", "июня" => "06",
                        "июля" => "07", "августа" => "08", "сентября" => "09",
                        "октября" => "10", "ноября" => "11", "декабря" => "12"
                    );

                    // Если дата в формате "18 сентября 2024"
                    if(preg_match("/(\d{1,2})\s([а-яё]+)\s(\d{4})/ui", $sDate, $arMatches)){
                        $sMonth = mb_strtolower($arMatches[2]);
                        if(isset($arMonths[$sMonth])){
                            $sPublishDate = sprintf("%02d.%s.%04d", $arMatches[1], $arMonths[$sMonth], $arMatches[3]);
                        }
                    }// Если дата в формате "18 сентября"
                    elseif(preg_match("/(\d{1,2})\s([а-яё]+)/ui", $sDate, $arMatches)){
                        $sMonth = mb_strtolower($arMatches[2]);
                        if(isset($arMonths[$sMonth])){
                            $sPublishDate = sprintf("%02d.%s", $arMatches[1], $arMonths[$sMonth]) . "." . (new DateTime())->format("Y");
                        }
                    }
                }elseif(isset($arReview["PUBLISH_DATE"])){
                    $oDateTime = new DateTime($arReview["PUBLISH_DATE"]);
                    $sPublishDate = $oDateTime->format("d.m.Y");
                }
            }catch(Exception $e){
                AddMessage2Log("Ошибка обработки даты: " . $e->getMessage());
            }

            $arFilter = array(
                "IBLOCK_ID" => $iIblockId,
                "SECTION_ID" => $iSectionId,
                "NAME" => $sAuthorName,
                "PROPERTY_PUBLISH_DATE_VALUE" => $sPublishDate
            );

            $rsElement = CIBlockElement::GetList(array(), $arFilter, false, false, array("ID", "PROPERTY_PUBLISH_DATE"));

            if(!$rsElement->Fetch()){
                // Добавление нового элемента
                $arAddFields = array(
                    "IBLOCK_ID" => $iIblockId,
                    "IBLOCK_SECTION_ID" => $iSectionId,
                    "SORT" => $iSort,
                    "NAME" => $sAuthorName,
                    "PREVIEW_TEXT" => $sPreviewText,
                    "PROPERTY_VALUES" => array(
                        "REVIEW_RATING" => $iRating,
                        "PUBLISH_DATE" => $sPublishDate,
                    )
                );
                // Добавляем аватар (Preview Picture)
                if($sAvatarUrl !== ""){
                    $arFileAvatar = downloadImageFile($sAvatarUrl);
                    if($arFileAvatar !== false){
                        $arAddFields["PREVIEW_PICTURE"] = $arFileAvatar;
                    }
                }
                // Добавляем галерею
                $arGalleryFiles = array();
                foreach($arGallery as $sImgUrl){
                    if($sImgUrl !== ""){
                        $arFile = downloadImageFile($sImgUrl);
                        if($arFile !== false){
                            $arGalleryFiles[] = array("VALUE" => $arFile);
                        }
                    }
                }
                if(!empty($arGalleryFiles)){
                    $arAddFields["PROPERTY_VALUES"]["REVIEW_GALLERY"] = $arGalleryFiles;
                }
                $oElement = new CIBlockElement;
                if(!$oElement->Add($arAddFields)){
                    AddMessage2Log("Ошибка добавления элемента: " . $oElement->LAST_ERROR);
                }
            }
        }
    }

    if(!empty($arYandexReviews)){
        ProcessReviews($arYandexReviews, $iYandexSectionId, $iIblockId);
    }
    if(!empty($arGisReviews)){
        ProcessReviews($arGisReviews, $i2GisSectionId, $iIblockId);
    }

    return "Yandex2GisReviewsAgent();";
}