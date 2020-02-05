<?php

namespace App\Services\MotifService\ItemSpecificParser;

class ParsedHeader
{
  public $id;
  public $title;

  public function __construct($id, $title) 
  {
    $this->id = trim($id);
    $this->title = trim($title);
  }
}

