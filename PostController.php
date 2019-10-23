<?php

namespace App\Http\Controllers\Admin;

use App\DataTables\PostDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\UpdatePostCategoryRequest;
use App\Http\Requests\Admin\Post\UpdatePostPriceRequest;
use App\Http\Requests\Admin\Post\UpdatePostRequest;
use App\Http\Requests\Admin\Post\CreatePostNoteRequest;
use App\Http\Requests\Admin\Post\StorePostMessageRequest;
use App\Models\Category;
use App\Models\Credit;
use App\Models\PostStatus;
use App\Models\Shop;
use App\Models\User;
use App\Services\CategoryService\CategoryService;
use App\Services\CreditsService\CreditsService;
use App\Services\MturkService\MturkService;
use App\Services\PostService\PostService;
use Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Response;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\PostImage;
use App\Services\MediaService\MediaServiceInterface;
use App\Services\PriceAnalysis\AnalyzerFactory;
use App\Services\ShopAdapter\Shops\Ebay\Shopping\ShoppingService;
use App\Services\ExecutionLogger;

class PostController extends Controller
{
    protected $postService;
    protected $shopppingService;

    // TODO:(ruslan)
    // this property seems unused
    protected $priceService;

    public function __construct(PostService $postService, ShoppingService $shoppingService, MturkService $mturkService)
    {
        $this->authorizeResource(Post::class, 'post');
        $this->postService = $postService;
        $this->shoppingService = $shoppingService;
        $this->mturkService = $mturkService;
    }

    public function index(PostDataTable $postDataTable, PostStatus $postStatus, Post $post)
    {
        $postStatuses = $postStatus->withCount('posts')->orderBy('order')->get();
        $count = $post->count();

        return $postDataTable->render('admin.posts.index', compact('postStatuses', 'count'));
    }

    public function storeNote(Post $post, CreatePostNoteRequest $request)
    {
        $note = $request->input('text');
        $this->postService->storePostNote($post, Auth::user(), $note);
        $notes = $post->notes()->with('user')->get();
        $post->setExpertsInfo();

        return response()->json(compact('notes'));
    }

    public function storeMessage(Post $post, StorePostMessageRequest $request)
    {
        ob_start();
        $this->postService->createPostMessage($post, Auth::user(), $request);
        ob_end_clean();

        $messages = $post
                  ->messages()
                  ->orderBy('created_at', 'desc')
                  ->with('sender', 'recipient')
                  ->get();

        return response()->json(compact('messages'));
    }

    public function edit(Post $post)
    {
        $post = $this->postService->loadAllRelatedData($post);

        // load condition relation since we're going to use it in Vue
        $post->load('userDefinedCondition');
        $post->logActivityOpenedAt(Auth::user());

        if ($post->isImagesNumberExceeded()) {
            Flash::warning('Too many photos. Select the best 12 photos.');
        }

        $mediaService = \App::make(MediaServiceInterface::class);
        $mainStatuses = PostStatus::main()->get();
        $shopsForPosting = Shop::enabledForPosting()->get();

        $marketPrices = $this->postService->getMarketPriceRange($post);
        $shippingPrices = $this->postService->getShippingPriceRange($post);

        $ebayPrices = (object)[
            'maxMarketPrice' => round($marketPrices['max'], 2),
            'minMarketPrice' => round($marketPrices['min'], 2),
            'avgMarketPrice' => round($marketPrices['avg'] ,2),
            'maxShippingPrice' => $shippingPrices['max'],
            'minShippingPrice' => $shippingPrices['min'],
        ];

        return view('admin.posts.edit.index', compact(
            'post',
            'mediaService',
            'mainStatuses',
            'ebayPrices',
            'shopsForPosting'
        ));
    }

    public function update(Post $post, UpdatePostRequest $request)
    {
        $this->postService->updatePost($post, $request->only('title', 'content', 'upc'), PostStatus::HAS_DESCRIPTION);

        if (empty($request->input('ebay_condition_id'))) {
            $request->session()->flash('forcetab', 'description');
            Flash::error('Please choose a condition.');
            return back();
        }

        list($condition_id, $condition_name) = explode(':', $request->input('ebay_condition_id'));
        $this->postService->updatePostEbayCondition($post, $condition_id, $condition_name);

        Flash::success('Post Title / Description updated successfully.');
        $post->setExpertsInfo();
        return back();
    }

    public function updatePrice($post, UpdatePostPriceRequest $request)
    {
        $postInstance = $this->postService->getPostOrTemplateById($post);

        $data = $request->only('price', 'shipping_type_id');
        $this->postService->updatePost($postInstance, $data,PostStatus::HAS_PRICE);

        $postInstance->setExpertsInfo();
        Flash::success('Post Price updated successfully.');
        return back();
    }

    public function sendToManagerReview(Post $post)
    {
        $this->postService->syncPostStatus($post, PostStatus::MANAGER_REVIEW);
        Flash::success('Post sent to manager review successfully.');

        return back();
    }

    public function refreshSpecifics(Post $post, CategoryService $categoryService)
    {
        $post->category->metaTypes()->detach();
        $categoryService->freshCategoryMetaData($post->category_id);

        // if post has data from catalog we need
        // to compbine its data with suggested meta types/options
        if ($post->hasCatalogItemSpecifics()) {
            $categoryService->updateMetaDataWithCatalogInfo(
                $post->category->id,
                $post->catalog_item_specifics,
                $this->postService,
                $post
            );
        }
        $post->setExpertsInfo();
        Flash::success("Category's item specifics were updated.");
        return back();
    }

    public function updateCategorySection($post, Request $request, CategoryService $categoryService)
    {
        $action = $request->input('action');
        $postInstance = $this->postService->getPostOrTemplateById($post);
        $postInstance->setExpertsInfo();

        // if user just want to update keywords, let's do it
        if ($action == 'keywords') {
            ExecutionLogger::registerLogger('update keywords');

            $this->postService->updatePost(
                $postInstance,
                $request->only(['keywords']),
                '',
                true,
                [PostStatus::HELP_WANTED]
            );
            Flash::success('Post keywords have been updated.');

            // we want to make sure when keywords are updated
            // we show category tab instead of catalog
            // let's write some info about it to the session
            // and our tab handler will check this value out.
            ExecutionLogger::measureAndLog('update keywords');
            $request->session()->flash('forcetab', 'category');
        }


        // if user has category selected and want to submit
        // and want to assign categoy to a post
        if ($action == 'use-ebay' || $action == 'use-crowd' || $action == 'mturk-preview') {
            ExecutionLogger::registerLogger('update category from ebay');
            $categoryService->freshCategoryMetaData($request->input('category_id'));

            // if post has data from catalog we need
            // to compbine its data with suggested meta types/options
            if ($postInstance->hasCatalogItemSpecifics()) {
                $categoryService->updateMetaDataWithCatalogInfo(
                    $request->input('category_id'),
                    $postInstance->catalog_item_specifics,
                    $this->postService,
                    $postInstance
                );
            }

            $this->postService->updatePost(
                $postInstance,
                $request->only(['category_id', 'keywords', 'using_motif']),
                PostStatus::CATEGORIZED,
                true,
                [PostStatus::HELP_WANTED]
            );

            Flash::success('Post category have been updated.');
            ExecutionLogger::measureAndLog('update category from ebay');

            // submit to crowd if user wanted
            if ($action == 'use-crowd') {
                $this->postService->createHIT($postInstance);
                $this->postService->syncPostStatus($postInstance, PostStatus::SENT_TO_CROWD);
            }

            // if user just want to see preview of mturk template
            // before sending to crowd
            if ($action == 'mturk-preview') {
                $mturkData[] = $this->mturkService->generateHitForPostButDontSend($postInstance);
                return view('admin.motif.test.template-preview',  compact('mturkData'));
            }
        }

        // if user just want to apply selected post template
        if ($action == 'apply-post-template') {
            ExecutionLogger::registerLogger('apply post template');
            $postTemplate = PostTemplate::find($request->input('template_id'));

            if (empty($postTemplate)) {
                return redirect('/admin/posts/' . $postInstance->id . '/edit')
                                                ->with('template_status.error', 'The template was not found.');
            }

            $this->postService->updatePostFromTemplate($postInstance, $postTemplate);

            ExecutionLogger::measureAndLog('apply post template');
            Flash::success('Post template was applied.');
        }


        // Post templates are calling this method as well.
        // Let's use back() function so user will see the same page
        // where his was before. In case of Post it will be post/{id}/eidt
        // and in case of post templates it will be post-templates/{id}/edit
        return back();
    }

    public function destroy(Post $post)
    {
        $this->postService->deletePost($post);
        $post->setExpertsInfo();
        Flash::success('Post deleted successfully.');

        return redirect(route('admin.posts.index'));
    }

    public function storeMetaOptions(Request $request, $post)
    {
        $postInstance = $this->postService->getPostOrTemplateById($post);
        $postInstance->setExpertsInfo();
        $this->postService->savePostsMetaOptions($postInstance, $request->except('_token'));
        $this->postService->syncPostStatus($postInstance, PostStatus::HAS_META);

        Flash::success('Post Meta Options saved successfully.');

        if ($postInstance->isTemplate()) {
            return redirect(route('admin.post-templates.edit', $postInstance));
        }

        if (empty($postInstance->title)) {
            $this->postService->generateAndSaveTitle($postInstance);
        }
        if (empty($postInstance->content)) {
            $this->postService->generateAndSaveDescription($postInstance);
        }

        if (empty((float)$postInstance->price)) {
            $this->postService->generateAndSavePrice($postInstance);
        }

        return back();
    }

    public function replaceImage(Request $r)
    {
        $image = PostImage::where('url', $r->input('find_url'))->first();
        $image->replaceUrl($r->input('replace_url'));

        return response()->json(['data' => $image]);
    }

    public function createTemplate(Post $post, Request $r)
    {
        $template_name = $r->input('template_name');
        if (empty($template_name)) {
            return redirect('/admin/posts/' . $post->id . '/edit')->with('template_status.error', 'Template name cannot be empty');
        }

        PostTemplate::createTemplateFromPost($post, $template_name);

        $message = 'Template "'.$template_name.'" has been saved. Please find it in Post Templates section';
        return redirect('/admin/posts/' . $post->id . '/edit')
                                        ->with('template_status.success', $message);
    }

    public function updatePostFromTemplate(Post $post, Request $r)
    {
        $postTemplate = PostTemplate::find($r->input('selected_template'));
        if (empty($postTemplate)) {
            return redirect('/admin/posts/' . $post->id . '/edit')
                                            ->with('template_status.error', 'The template was not found.');
        }

        $this->postService->updatePostFromTemplate($post, $postTemplate);

        $message = 'Post has been filled with data from "' . $postTemplate->title . '" template';
        return redirect('/admin/posts/' . $post->id . '/edit')->with('template_status.success', $message);
    }

    public function verifyAddItem(Post $post, Request $request)
    {
        $errors = $this->postService->verifyAddItem($post, $request->input('shop_alias', ''));

        return response()->json(compact('errors'));
    }

    public function sendForCustomerReview(Post $post)
    {
        $this->postService->sendForReview($post);
        $post->setExpertsInfo();

        Flash::success('Post was sent to client review successfully.');
        return back();
    }

    public function priceAnalysis($instance, $keywords, Request $r)
    {
        $remoteService = AnalyzerFactory::getAnalyzerByName($r->input('analyzer'));
        $condition = $r->input('condition');
        if (!$remoteService) {
            abort(400);
        }

        $data = [];

        if ($instance == 'sold') {
            $data = $remoteService->getSold($keywords, $condition);
        }

        if ($instance == 'current') {
            $data = $remoteService->getCurrent($keywords, $condition);
        }

        return response()->json($data);
    }

    public function helpWanted(Post $post, Request $request)
    {
        $note = $request->input('message');

        // modify note to make it noticeble
        $note = 'Help Wanted: ' . $note;
        $this->postService->storePostNote($post, Auth::user(), $note);

        // mark post
        $post->helpWanted();
        $post->statuses()->attach(PostStatus::whereAlias(PostStatus::HELP_WANTED)->firstOrFail()->id);
        $post->setExpertsInfo();

        return response()->json();
    }

    public function helpProvided(Post $post, Request $request)
    {
        $note = $request->input('message');

        // modify note to make it noticeble
        $note = 'Help Provided: ' . $note;
        $this->postService->storePostNote($post, Auth::user(), $note);

        // mark post
        $post->helpProvided();
        $post->statuses()->detach(PostStatus::whereAlias(PostStatus::HELP_WANTED)->firstOrFail()->id);
        $post->setExpertsInfo();

        return response()->json();
    }

    public function deleteImageById($image_id)
    {
        $image = PostImage::find($image_id);
        if ($image) {
            $image->delete();
        }

        return back();
    }

    public function fullUpdate(Post $post, Request $r)
    {
        $this->postService->updatePost($post, $r->only('title', 'content', 'upc'), PostStatus::HAS_DESCRIPTION);
        $this->postService->savePostsMetaOptions($post, $r->except(
            '_token',
            'title',
            'content',
            'upc',
            'price',
            'shipping_type_id',
            'ebay_condition_id'
        ));
        $this->postService->updatePost($post, $r->only('price', 'shipping_type_id'), PostStatus::HAS_PRICE);


        if (empty($r->input('ebay_condition_id'))) {
            Flash::error('Please choose a condition.');
            return back();
        }

        // parse ebay_condition_id since it has
        // id:name format
        list($condition_id, $condition_name) = explode(':', $r->input('ebay_condition_id'));

        $this->postService->updatePostEbayCondition($post, $condition_id, $condition_name);
        $post->setExpertsInfo();

        Flash::success('The post data has been updated.');

        return back();
    }

    public function imgReorder(Post $post, Request $r)
    {
        $orderedLinks = json_decode($r->input('currentList'));

        if (!$orderedLinks) {
            return;
        }

        foreach ($post->images as $image) {
            $image->weight = array_search($image->url, $orderedLinks);
            $image->save();
        }
        $post->setExpertsInfo();

        return response()->json([
            'status' => 'ok'
        ]);
    }

    public function lockThePost(Post $post)
    {
        $lockInfo = $post->getLockInfo();

        if (!$lockInfo->status) {
            $post->lock();
        }

        if ($lockInfo->status && (\Auth::user()->id == $lockInfo->author_id)) {
            $post->lock();
            return;
        }
    }

    public function isPostLocked(Post $post)
    {
        $lockInfo = $post->getLockInfo();
        return response()->json((array)$lockInfo);
    }

    /**
     * @param Post $post
     * @throws \Exception
     */
    public function setLastSeenSellerMessagesDate(Post $post)
    {
        Auth::user()->hasSeenSellerAnswers()->detach($post);
        Auth::user()->hasSeenSellerAnswers()->attach(
            $post, ['last_seen' => new \DateTimeImmutable()]);
        return response()->json();
    }

    public function generateTitle(Post $post)
    {
        return response()->json([
            'title' => $this->postService->generateTitle($post)
        ]);
    }

    public function addStatus(Post $post, Request $r)
    {
        $status = $r->input('status');
        $this->postService->syncPostStatus($post, $status);
        return redirect(route('admin.posts.edit', ['post' => $post->id]));
    }

    public function removeStatus(Post $post, Request $r, $status=null)
    {
        if (!$status) {
            $status = $r->input('status');
        }

        $statusModel = PostStatus::where('alias', $status)->firstOrFail();

        if ($statusModel->alias === PostStatus::HELP_WANTED) {
            $post->helpProvided();
        }

        $post->statuses()->detach([$statusModel->id]);

        return redirect(route('admin.posts.edit', ['post' => $post->id]));
    }

    /**
     * TODO:
     * This method currently works only for first
     * marketplace (usually eBay).
     * When more marketplace are implemented
     * make sure to change this method
     */
    public function resend(Post $post)
    {
        $post->shops[0]->pivot->delete();
        $statusIds = PostStatus::whereIn('alias', [
            PostStatus::CATEGORIZED,
            PostStatus::HAS_META,
            PostStatus::HAS_DESCRIPTION,
            PostStatus::HAS_PRICE,
        ])->get()->map(function($status) {
            return $status->id;
        });
        $post->statuses()->detach();
        $post->statuses()->attach($statusIds);

        $this->postService->sendForReview($post);
        $post->setExpertsInfo();

        Flash::success('Post has been successfully re sent to the seller.');
        return back();
    }
}
