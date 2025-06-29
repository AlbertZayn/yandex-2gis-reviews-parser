<?php

namespace Mendeleev\Lib\ReviewParser;

use DOMDocument;
use DOMXPath;
use DOMElement;

/**
 * Класс для парсинга отзывов с Яндекс Карт
 */
class YandexReviewParser
{
    /** @var DOMDocument $oDom Документ для парсинга */
    private DOMDocument $oDom;

    /**
     * Конструктор класса
     *
     * @param DOMDocument $oDom Документ для парсинга
     */
    public function __construct(DOMDocument $oDom)
    {
        $this->oDom = $oDom;
    }

    /**
     * Парсинг рэйтинга компании
     *
     * @return array Массив с данными карточки компании
     */
    public function parseRatingSummary(): array
    {
        $oXpath = new DOMXPath($this->oDom);
        $oRatingSummary = $oXpath->query(
            "//div[contains(@class, \"business-summary-rating-badge-view__rating-and-stars\")]"
        );
        $oSummaryList = $oRatingSummary->item(0);

        return array(
            "SUMMARY_RATING" => $this->getCompanyCard($oXpath, $oSummaryList)
        );
    }

    /**
     * Парсинг основной и составной части рэйтинга
     *
     * @param DOMXPath $oXpath
     * @param DOMElement $oSummaryList
     * @return array
     */
    private function getCompanyCard(DOMXPath $oXpath, DOMElement $oSummaryList): array
    {
        //Основная и составная часть рэйтинга
        $oNodeList = $oXpath->query(
            ".//span[contains(@class,'business-summary-rating-badge-view__rating-text') and not(contains(@class,'_separator'))]",
            $oSummaryList
        );
        $nSummaryMain = $oNodeList->item(0)?->textContent ?: "—";
        $nSummaryComposite = $oNodeList->item(1)?->textContent ?: "—";

        return array(
            "FULL_RATING" => (float)(trim($nSummaryMain) . "." . trim($nSummaryComposite))
        );

    }

    /**
     * Парсинг количества отзывов
     *
     * $param DOMXPath $oXpath
     * @param mixed $oReview
     * @return string
     */
    public function getAmountReviews(): string
    {
        $oXpath = new DOMXPath($this->oDom);
        $sAmountReviews = $oXpath->query(
                "//h2[contains(@class, \"card-section-header__title\")]"
        )->item(0)->textContent ?? "0 Отзывов";

        return $sAmountReviews;
    }

    /**
     * Парсит отзывы из документа
     *
     * @return array Массив с данными отзывов
     */
    final public function parseReviews(): array
    {
        $oXpath = new DOMXPath($this->oDom);
        $oReviews = $oXpath->query(
            "//div[contains(@class, \"business-reviews-card-view__review\")]"
        );

        $arResult = array();

        foreach($oReviews as $oReview){
            $oReviewXpath = new DOMXPath($oReview->ownerDocument);

            $arResult[] = array(
                "AUTHOR" => $this->getAuthorInfo($oReviewXpath, $oReview),
                "RATING" => $this->getRating($oReviewXpath, $oReview),
                "TEXT" => $this->getReviewText($oReviewXpath, $oReview),
                "GALLERY" => $this->getReviewGallery($oReviewXpath, $oReview),
                "DATE" => $this->getReviewDate($oReviewXpath, $oReview),
            );
        }

        return $arResult;
    }

    /**
     * Получает информацию об авторе отзыва
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return array
     */
    private function getAuthorInfo(DOMXPath $oXpath, object $oReview): array
    {
        $sAuthor = $oXpath->query(
                ".//span[@itemprop=\"name\"]",
                $oReview
        )->item(0)->textContent ?? "Автор не указан";
        $sAvatar = $this->getAuthorAvatar($oXpath, $oReview);

        return array(
            "name" => $sAuthor,
            "avatar" => $sAvatar
        );
    }

    /**
     * Получает аватар автора
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return string
     */
    private function getAuthorAvatar(DOMXPath $oXpath, object $oReview): string
    {
        $oAvatar = $oXpath->query(
            ".//div[contains(@class, \"user-icon-view__icon\")]",
            $oReview
        )->item(0);

        if($oAvatar){
            $sStyle = $oAvatar->getAttribute("style");
            if(preg_match('/url\(["\']?(.*?)["\']?\)/', $sStyle, $arMatches)){
                return $arMatches[1];
            }
        }

        return "";
    }

    /**
     * Получает рейтинг отзыва
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return int
     */
    private function getRating(DOMXPath $oXpath, object $oReview): int
    {
        //Коробка звёзд внутри отзыва
        $starContainer = $oXpath->query(
            ".//div[contains(@class, 'business-rating-badge-view__stars')]",
            $oReview
        )->item(0);

        //Подсчёт только полных звёзд внутри этой коробки
        $fullStars = $oXpath->query(
            ".//span[contains(concat(' ', normalize-space(@class), ' '), ' _full ')]",
            $starContainer
        );

        return $fullStars->count();
    }

    /**
     * Получает текст отзыва
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return string
     */
    private function getReviewText(DOMXPath $oXpath, object $oReview): string
    {
        return $oXpath->query(
                ".//span[@class=\" spoiler-view__text-container\"]",
                $oReview
        )->item(0)->textContent ?? "Нет текста";
    }

    /**
     * Получение галереии картинок загруженных в отзыв
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return array
     */
    private function getReviewGallery(DOMXPath $oXpath, object $oReview): array
    {
        $arUrls = array();
        $oGallery = $oXpath->query(
            ".//img[contains(@class, 'business-review-media__item-img')]",
            $oReview
        );

        foreach($oGallery as $oImg){
            $sSrc = $oImg->getAttribute("src");
            if($sSrc){
                $arUrls[] = str_replace("/S", "/XXXL", $sSrc);
            }
        }
        return $arUrls;
    }

    /**
     * Получаем дату отзыва
     *
     * @param DOMXPath $oXpath
     * @param mixed $oReview
     * @return string
     */
    private function getReviewDate(DOMXPath $oXpath, object $oReview): string
    {
        return $oXpath->query(
                ".//span[@class=\"business-review-view__date\"]/span",
                $oReview
            )->item(0)->textContent ?? "Дата неизвестна";
    }
}