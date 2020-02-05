<?php

namespace App\Services\MotifService\ItemSpecificParser;

interface IItemSpecificParser
{
  /**
   * pass a source of parsing here (csv, xsl, etc...)
   *
   * @return IItemSpecificParser
   */
  public static function performParsingOn($source);

  /**
   * fetch header row and return it
   * as array of columns (titles)
   *
   * @return ParsedHeader[]
   */
  public function getHead();

  /**
   * get values (options) from column by header
   *
   * @return array
   */
  public function getValuesByHeader(ParsedHeader $header);
}
