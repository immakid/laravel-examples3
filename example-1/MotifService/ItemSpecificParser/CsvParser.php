<?php

namespace App\Services\MotifService\ItemSpecificParser;

class CsvParser implements IItemSpecificParser
{
  private $csvContent;

  /**
   * pass a source of parsing here (csv)
   *
   * @return IItemSpecificParser
   */
  public static function performParsingOn($source)
  {
    if (!file_exists($source)) {
      return null;
    }

    $instance = new self;
    $instance->setCsvContent(
      array_map(
        'str_getcsv',
        // this scary regexp is used to split file content to separated lines
        // but thanks to this regexp we don't treat \n symbol in quotes (read - \n symbol in field) as 
        // new line symbol.
        preg_split('/[\r\n]{1,2}(?=(?:[^\"]*\"[^\"]*\")*(?![^\"]*\"))/', file_get_contents($source))
      )
    );

    return $instance;
  }

  public function setCsvContent($content) 
  {
    $this->csvContent = $content;
  }

  /**
   * fetch header row and return it
   * as array of columns (titles)
   *
   * @return ParsedHeader[]
   */
  public function getHead()
  {
    if (empty($this->csvContent[0])) {
      return [];
    }

    $headersList = [];
    foreach ($this->csvContent[0] as $index => $header) {
      $headersList[] = new ParsedHeader($index, $header);
    }

    return $headersList;
  }

  /**
   * get values (options) from column by header
   *
   * @return array
   */
  public function getValuesByHeader(ParsedHeader $header)
  {
    $values = [];
    $numberOfRows = count($this->csvContent);

    for ($rowNumber = 1; $rowNumber < $numberOfRows; $rowNumber++) {
      if (!isset($this->csvContent[$rowNumber][$header->id])) {
        continue;
      }

      $values[] = $this->csvContent[$rowNumber][$header->id];
    }

    return array_filter(array_map('trim', $values));
  }
}
