<?php

namespace App\Traits;

use App\Helpers\Rules\TitleGenerator;
use App\Helpers\Rules\Fashion\Fashion;
use App\Helpers\Rules\RulesRunner;
use App\Helpers\DataGeneratorUtil;

/**
 * We current work only with Post model
 * and use relations of this model.
 * Extend this trait when you want to 
 * use it with another class.
 */
trait CanGenerateData
{
    public function generateTitle() 
    {
        /**
         * structure of this array is:
         * ['Item Specific Name' => 'Selected Value', ...] 
         */
        $itemSpecificValues = [];

        foreach ($this->metaOptions as $option) {
            $itemSpecificValues[$option->name] = $this
                ->getMetaTypeValue($option->meta_type_id);
        }

        $motif = $this->category->motif;
        $generator = \App::make(DataGeneratorUtil::class);
        return $generator->generateTitle($motif, 80, $itemSpecificValues);
    }

    public function generateDescription()
    {
        /**
         * structure of this array is:
         * ['Item Specific Name' => 'Selected Value', ...] 
         */
        $itemSpecificValues = [];

        foreach ($this->metaOptions as $option) {
            $itemSpecificValues[$option->name] = $this
                ->getMetaTypeValue($option->meta_type_id);
        }

        $motif = $this->category->motif;
        $generator = \App::make(DataGeneratorUtil::class);
        return $generator->generateDescription($motif, $itemSpecificValues);
    }

    public function generatePrice()
    {
        /**
         * structure of this array is:
         * ['Item Specific Name' => 'Selected Value', ...] 
         */
        $itemSpecificValues = [];

        foreach ($this->metaOptions as $option) {
            $itemSpecificValues[$option->name] = $this
                ->getMetaTypeValue($option->meta_type_id);
        }

        $motif = $this->category->motif;
        $generator = \App::make(DataGeneratorUtil::class);
        return $generator->generatePrice($motif, $itemSpecificValues);
    }
}

