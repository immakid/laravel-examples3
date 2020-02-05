<?php
/**
 * Created by PhpStorm.
 * User: suhovoy
 * Date: 06.02.18
 * Time: 11:56
 */

namespace App\Services\MotifService;

use Carbon\Carbon;
use App\Models\Category;
use App\Models\Motif;
use App\Models\MetaType;
use App\Services\MetaTypeService\MetaTypeService;
use App\Services\MotifService\ItemSpecificParser\CsvParser;

class MotifService
{
    /**
     * @var Motif
     */
    protected $motif;

    protected $metaTypeService;

    public function __construct(Motif $motif, MetaTypeService $metaTypeService)
    {
        $this->motif = $motif;
        $this->metaTypeService = $metaTypeService;
    }

    public function createMotifFromCategory(Category $category):Motif
    {
        $this->updateOrCreateMotif($category->toArray());
        $this->attachMetaTypesToMotif($category->metaTypes->pluck('id')->toArray());

        return $this->motif;
    }

    public function createMotif(array $data):Motif
    {
        return $this->updateOrCreateMotif($data);
    }

    private function createMetaType($name)
    {
      return $this->metaTypeService->createMetaType($name, [
        'is_required' => false,
        'is_active' => true,
        'is_custom' => true
      ]);
    }

    public function createMotifFromFile($name, $file)
    {
        $this->motif->fill([
            'name' => $name
        ])->save();

        // parse file using csv parser
        $parser = CsvParser::performParsingOn($file->path());

        // fetch all headers and go through them
        $headers = $parser->getHead();
        foreach ($headers as $header) {
            // save meta type by name
            $metaType = $this->createMetaType($header->title);

            // fetch possible options of type and attach them to the metatype
            $options = $parser->getValuesByHeader($header);
            $this->metaTypeService->refreshMetaOptions($metaType->id, $options);

            // attach meta type of motif
            $this->metaTypeService->attachMetaTypeToEntity(
                $metaType,
                $this->motif->id,
                Motif::class
            );
        }
    }

    public function updateMotif(Motif $motif, array $data):Motif
    {
        $this->motif = $motif;

        return $this->updateOrCreateMotif($data);
    }

    public function publishMotif(Motif $motif):Motif
    {
        return $this->updateMotif($motif, ['publish_at' => Carbon::now()]);
    }

    public function assocMotif(Motif $motif, Category $category)
    {
        return $motif->categories()->save($category);
    }

    /**
     * When motif is removed
     * we are going to clear the 
     * connection between the motif
     * and category it was assigned to
     */
    public function destroy(Motif $motif):void
    {
        Category::where('motif_id', $motif->id)
            ->get()
            ->map(function($category) {
                $category->motif_id = null;
                $category->save();
            });

        $motif->delete();
    }

    protected function updateOrCreateMotif(array $data):Motif
    {
        $this->motif->fill($data);

        if(!empty($data['arts'])) {
            $this->motif->additional_arts = $this->storeArts($data['arts']);
        }
        $this->motif->save();

        return $this->motif;
    }

    protected function storeArts($uploadedFiles) 
    {
        $links = [];
        foreach ($uploadedFiles as $file) {
            $links[] = \Storage::disk('s3')->url(
                \Storage::disk('s3')->putFile('additional_arts', $file)
            );
        }

        \Log::debug('images uploaded');
        \Log::debug($links);
        return $links;
    }

    protected function attachMetaTypesToMotif(array $ids):void
    {
        $this->motif->metaTypes()->attach($ids);
    }
}
