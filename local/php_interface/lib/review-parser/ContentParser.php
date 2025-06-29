<?php

namespace Mendeleev\Lib\ReviewParser;

use RuntimeException;
use DOMDocument;

/**
 * Класс для парсинга HTML-контента
 */
class ContentParser
{
    /**
     * @var string $sHtml HTML-содержимое страницы
     */
    private string $sHtml;

    /**
     * Конструктор класса
     *
     * @param string $sUrl URL страницы для парсинга
     * @throws RuntimeException Если не удалось загрузить страницу
     */
    public function __construct(string $sUrl)
    {
        $sHtml = file_get_contents($sUrl);

        if($sHtml === false){
            throw new RuntimeException("Не удалось загрузить страницу.");
        }

        $this->sHtml = $sHtml;
    }

    /**
     * Возвращает DOMDocument с разобранным HTML
     *
     * @return DOMDocument
     * @throws RuntimeException Если не удалось разобрать HTML
     */
    final public function getContent(): DOMDocument
    {
        $oDom = new DOMDocument();
        libxml_use_internal_errors(true);

        if(!$oDom->loadHTML($this->sHtml)){
            throw new RuntimeException("Ошибка при разборе HTML.");
        }

        return $oDom;
    }
}