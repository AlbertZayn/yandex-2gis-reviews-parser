<?php

namespace Mendeleev\Lib\ReviewParser;

use Bitrix\Main\Grid\Declension;

/**
 * Парсинг отзывов 2GIS через публичный API (JSON)
 */
class DoubleGisReviewParserJson
{
    private const API_URL = "https://public-api.reviews.2gis.com/2.0/branches/<ВАШ_BRANCH_ID>/reviews";
    private const API_KEY = "<ВАШ_PUBLIC_API_KEY>";
    private const DEFAULT_LIMIT = 50;

    /** Внутренний кеш JSON-ответа */
    private ?array $jsonCache = null;

    /**
     * Возвращает количество отзывов в виде строки с правильным склонением слова "отзыв"
     */
    public function getAmountReviews(): string
    {
        $oData = $this->fetchReviewsJson();
        $nCount = (int)($oData["meta"]["branch_reviews_count"] ?? 0);
        $oDecl = new Declension("отзыв", "отзыва", "отзывов");
        return $nCount . " " . $oDecl->get($nCount);
    }

    /**
     * Возвращает общий рейтинг компании
     */
    public function parseRatingSummary(): array
    {
        $oData = $this->fetchReviewsJson();
        $fRating = $oData["meta"]["branch_rating"] ?? 0;
        return array("FULL_RATING" => (float)$fRating);
    }

    /**
     * Форматирует дату создания отзыва с учетом московского часового пояса
     *
     * @param array $arReview Массив данных отзыва (должен содержать ключ date_created)
     * @return string
     */
    private function getFormattedDate(array $arReview = array()): string
    {
        if(empty($arReview["date_created"])){
            return "";
        }

        try{
            $oDateTime = new \DateTime($arReview["date_created"]);
            $oDateTime->setTimezone(new \DateTimeZone("Europe/Moscow"));
            return $oDateTime->format("d.m.Y");
        }catch(\Exception $e){
            AddMessage2Log("Ошибка форматирования даты: " . $e->getMessage(), "error");
            return "";
        }
    }

    /**
     *  Собирает данные автора
     *
     * @param array $arUser
     * @return array
     */
    private function parseAuthorData(array $arUser): array
    {
        $sAuthorName = $arUser["name"] ?? "Автор не указан";
        $iAuthorId = $arUser["id"] ?? null;
        $arAvatars = "";

        if(!empty($arUser["photo_preview_urls"]["1920x"])){
            $arAvatars = $arUser["photo_preview_urls"]["1920x"];
        } elseif(!empty($arUser["photo_preview_urls"]["url"])){
            $arAvatars = $arUser["photo_preview_urls"]["url"];
        }

        return array(
            "name" => $sAuthorName,
            "avatar" => $arAvatars,
            "id" => $iAuthorId,
        );
    }

    /**
     * Собирает галерею отзыва в fullhd формате
     *
     * @param array $arPhotos
     * @return array
     */
    private function parseGallery(array $arPhotos): array
    {
        $arGallery = array();

        foreach($arPhotos as $arPhoto){
            if(!empty($arPhoto["preview_urls"]["1920x"])){
                $arGallery[] = $arPhoto["preview_urls"]["1920x"];
            }elseif(!empty($arPhoto["url"])){
                $arGallery[] = $arPhoto["url"];
            }
        }

        return $arGallery;
    }

    /**
     * Парсит ВСЕ отзывы, возвращает массив вида:
     * array(
     *   0 => array(
     *     "AUTHOR"  => array("name"=>"…", "avatar"=>"https://…"),
     *     "RATING"  => 5,
     *     "TEXT"    => "…",
     *     "GALLERY" => array("https://…jpg", …),
     *   ),
     *   …
     * )
     */
    public function parseReviews(): array
    {
        $oData = $this->fetchReviewsJson();
        $arReviews = $oData["reviews"] ?? array();
        $arResult = array();

        foreach($arReviews as $arReview){
            $arUser = $arReview["user"] ?? array();
            $arPhotos = $arReview["photos"] ?? array();

            $arResult[] = array(
                "PUBLISH_DATE"  =>  $this->getFormattedDate($arReview),
                "AUTHOR"  => $this->parseAuthorData($arUser),
                "RATING"  => (int)($arReview["rating"] ?? 0),
                "TEXT"    => $arReview["text"] ?? "",
                "GALLERY" => $this->parseGallery($arPhotos),
            );
        }

        return $arResult;
    }

    /**
     * Получает URL для парсинга данных
     * @return string
    */
    private function getParsingJsonUrl(): string
    {
        $arQuery = http_build_query(array(
            "limit"                 => self::DEFAULT_LIMIT,
            "is_advertiser"         => "false",
            "fields"                => "meta.providers,meta.branch_rating,meta.branch_reviews_count,meta.total_count,reviews.hiding_reason,reviews.is_verified,reviews.emojis",
            "without_my_first_review" => "false",
            "rated"                 => "true",
            "sort_by"               => "friends",
            "key"                   => self::API_KEY,
            "locale"                => "ru_RU",
        ));
        $arQuery = str_replace("%2C", ",", $arQuery);

        return self::API_URL . "?" . $arQuery;
    }

    /**
     * Получает и кеширует JSON из API (публичный endpoint 2GIS)
     * @return array|null
     */
    private function fetchReviewsJson(): array|null
    {
        if($this->jsonCache !== null){
            return $this->jsonCache;
        }

        $sParsinJsonUrl = $this->getParsingJsonUrl();

        $oRaw = file_get_contents($sParsinJsonUrl);
        if($oRaw === false){
            return $this->jsonCache = null;
        }

        $oJson = json_decode($oRaw, true);
        if(!is_array($oJson)){
            return $this->jsonCache = null;
        }

        return $this->jsonCache = $oJson;
    }
}