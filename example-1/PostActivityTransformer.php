<?php

namespace App\Traits;

use Spatie\Activitylog\Models\Activity;
use App\Models\Category;
use App\Models\Post;
use App\Models\PostMetaOptions;
use App\Models\MetaType;
use App\Models\ShippingType;

trait PostActivityTransformer
{
    private $CUSTOM_ACTIVITIES = [
        'post_opened_at',
        'post_submitted_at',
    ];

    public function getWholeActivity()
    {
        $activity = Activity::where(function($q) {
            $q->where('subject_type', Post::class)
                ->where('subject_id', $this->id)
                ->whereIn('description', array_merge($this->CUSTOM_ACTIVITIES, ['updated']));
        })->orWhere(function ($q) {
            $q->where('subject_type', Category::class)
                ->where('description', 'updated')
                ->where('subject_id', $this->category_id);
        })->orWhere(function ($q) {
            $q->where('subject_type', PostMetaOptions::class)
                ->where('properties->attributes->post_id', $this->id);
        })->orderBy('activity_log.created_at', 'desc')
                  ->get();

        $transformed_activity = [];
        foreach ($activity as $activity_item) {
            foreach ($this->transformActivityItem($activity_item) as $result) {
                $transformed_activity[] = $result;
            }
        }

        return [
            'activities' => $transformed_activity,
            'listCustomActivities' => $this->CUSTOM_ACTIVITIES
        ];
    }

    protected function transformPostActivityItem($activity_item)
    {
        $properties = $activity_item->properties->toArray();

        /* Process custom activity */
        if (in_array($activity_item->description, $this->CUSTOM_ACTIVITIES)) {
            $properties = $activity_item->properties->toArray();

            return [[
                'subject'       => $properties['subject'],
                'description'   => $activity_item->description,
                'by'            => ($activity_item->causer ? $activity_item->causer->name : 'Unknown'),
                'at'            => $activity_item->created_at
            ]];
        }

        $transformed_items = [];
        foreach ($properties['attributes'] as $name => $value) {
            $transformed_item = [];

            if ($name == 'updated_at') {
                continue;
            }

            if ($name == 'catalog_item_specifics') {
                continue;
            }

            if ($name == 'shop_meta') {
                continue;
            }

            $transformed_item['subject'] = $name;
            $transformed_item['new'] = $value;
            $transformed_item['old'] = $properties['old'][$name];

            if ($name == 'category_id') {
                $new_category = Category::find($value);
                $old_category = Category::find($properties['old'][$name]);
                $transformed_item['new'] = ($new_category ? $new_category->name : 'Unknown');
                $transformed_item['old'] = ($old_category ? $old_category->name : 'no category assigned');
                $transformed_item['subject'] = 'category';

            }
            if ($name == 'shipping_type_id') {
                $new_shipping_type = ShippingType::find($value);
                $old_shipping_type = ShippingType::find($properties['old'][$name]);
                $transformed_item['new'] = ($new_shipping_type ? $new_shipping_type->name : 'Unknown');
                $transformed_item['old'] = ($old_shipping_type ? $old_shipping_type->name : 'no type assigned');
                $transformed_item['subject'] = 'shipping type';

            }

            $transformed_item['description'] = $activity_item->description;
            $transformed_item['by'] = ($activity_item->causer ? $activity_item->causer->name : 'Unknown');
            $transformed_item['at'] = $activity_item->created_at;

            $transformed_items[] = $transformed_item;
        }

        return $transformed_items;
    }

    protected function transformPostMetaOptionActivityItem($activity_item)
    {
        $transformed_items = [];
        $properties = $activity_item->properties->toArray();
        $attributes = $properties['attributes'];

        $metaTypeName = 'Unknown Meta Type';
        $metaType = MetaType::where('id', $attributes['meta_type_id'])->first();
        if ($metaType) {
            $metaTypeName = ucfirst($metaType->name);
        }

        $transformed_item['subject'] = 'Meta option ('.$metaTypeName.')';
        $transformed_item['new'] = $attributes['value'];
        $transformed_item['old'] = '';

        $transformed_item['description'] = 'changed';
        $transformed_item['by'] = ($activity_item->causer ? $activity_item->causer->name : 'Unknown');
        $transformed_item['at'] = $activity_item->created_at;

        $transformed_items[] = $transformed_item;

        return $transformed_items;
    }

    protected function transformActivityItem($activity_item)
    {
        $transformed_items = [];
        $subject_type = $activity_item->subject_type;

        if ($subject_type == Post::class) {
            $transformed_items = array_merge($transformed_items, $this->transformPostActivityItem($activity_item));
        }

        if ($subject_type == PostMetaOptions::class) {
            $transformed_items = array_merge($transformed_items, $this->transformPostMetaOptionActivityItem($activity_item));
        }

        return $transformed_items;
    }
}
