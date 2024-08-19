<?php

namespace App\Http\Controllers;

use App\Bid;
use App\Auction;
use App\Product;
use App\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;


class MyController extends Controller
{  

    public static $productsPageSize = 50;
    public static $auctionInitialDurationInSeconds = 60 * 60;
    public static $completelyFruitelessAuctionInSeconds = 10 * 60;
    public static $noBidAuctionInSeconds = 5 * 60;


    public function products(Request $request)
    {
        if ( Auth::user()->role != 'admin' ){
            return redirect()->route('login');
        }
        $skip = 0;
        if ( $request->has('currentPage') ){
            $currentPage = (int) $request->currentPage;
            if ( $currentPage > 1 ){
                $skip = (($currentPage -1) * self::$productsPageSize);
            }
        }
        $products = Product::
        with('auction')
        ->with('auction.user')
        ->with('category')
        ->skip($skip)
        ->take(self::$productsPageSize)
        ->get()
        ->toArray();
        #dump($request->currentPage);
        return view('products', 
        [
            'page_size' => self::$productsPageSize,
            'products_count' => Product::all()->count(),
            'products' => $products,
            'categories' => Category::all(),
        ]);
    }


    public function addAuction(Request $request)
    {
        $product_id =  $request->input('product_id');
        $start_datetime =  $request->input('start_datetime');
        $opening_price =  $request->input('opening_price');
        if ( !is_numeric($product_id) ){
            return response()->json(['status' => 'error', 'message' => 'Δε δόθηκε έγκυρο προϊόν.']); 
        }
        if ( !is_numeric($opening_price) ){
            return response()->json(['status' => 'error', 'message' => 'Δε δόθηκε έγκυρη τιμή έναρξης.']); 
        }
        if ( empty($start_datetime) ){
            return response()->json(['status' => 'error', 'message' => 'Δε δόθηκε έγκυρη ημερομηνία.']); 
        }
        #DATETIMES
        $start_datetime_carbon = Carbon::createFromFormat('d-m-Y H:i:s', $start_datetime, 'Europe/Athens');
        if ( $start_datetime_carbon->isPast() ){
            return response()->json(['status' => 'error', 'message' => 'Η ημερομηνία έναρξης της δημοπρασίας δεν πρέπει να είναι παρελθοντική.']); 
        }
        $end_datetime_carbon = $this->getAuctionEndDatetime($start_datetime_carbon);

        $conflict = $this->isCandidateAuctionConflictingWithAnotherAuction($start_datetime_carbon->format('Y-m-d H:i:s'), $end_datetime_carbon->format('Y-m-d H:i:s'));
        if ( !empty($conflict) ){
            return response()->json(
                [
                    'status' => 'error', 
                    'debug' => $conflict,
                    'message' =>  'Υπάρχει ήδη δημοπρασία στην ημερομηνία και ώρα που επιλέξατε.'
            ]);
        }
  
        if ( $this->doAddAction($product_id, $start_datetime_carbon->format('Y-m-d H:i:s'), $end_datetime_carbon->format('Y-m-d H:i:s'), $opening_price) === true ){
            return response()->json(['status' => 'success', 'message' => true, 'debug' => $conflict]);
        }
    }


    public function doAddAction($product_id, $start_datetime, $end_datetime, $opening_price)
    {
        $auction = new Auction();
        $auction->opening_price = (float) $opening_price;
        $auction->product_id = $product_id;
        $auction->unique_id = Str::random(10);
        $auction->opening_price = $opening_price;
        $auction->start_datetime = $start_datetime;
        $auction->end_datetime = $end_datetime;
        $auction->auto_fruitless_seconds = self::$completelyFruitelessAuctionInSeconds;
        $auction->initial_duration_seconds = self::$auctionInitialDurationInSeconds;
        if ( $auction->save() ){
            return true;
        }
        return null;
    }


    public function getAuctionEndDatetime($datetime)
    {
        return $datetime->copy()->addSeconds(self::$auctionInitialDurationInSeconds);
    }


    public function isCandidateAuctionConflictingWithAnotherAuction($start_datetime, $end_datetime)
    {
        $conflictingAuction = Auction::
        whereNull('fruitless')
        ->whereNull('winner_user_id')
        ->where(function ($query) use($start_datetime, $end_datetime) {
            $query->whereBetween('start_datetime', [$start_datetime, $end_datetime])
                  ->orWhereBetween('end_datetime', [$start_datetime, $end_datetime])
                  ->orWhereRaw('? BETWEEN start_datetime and end_datetime', [$start_datetime]) 
                  ->orWhereRaw('? BETWEEN start_datetime and end_datetime', [$end_datetime]);
        })
        ->first();
        return $conflictingAuction;
    }


    public function addProduct(Request $request)
    {
        if ( Auth::user()->role != 'admin' ){
            return redirect()->route('login');
        }
        $product = new Product();
        $product->save();
        $product->name = $request->input('name');
        $product->price = (float) $request->input('price');
        $product->category_id = $request->input('category_id');
        $product->manufacturer = $request->input('manufacturer');
        $product->save();
        if ( $request->hasfile('image') ){
            $this->saveProductImage($request, $product->id);
        }
        return redirect()->back()->with('success_msg', 'Επιτυχής προσθήκη προϊόντος.');
    }


    private function saveProductImage(Request $request, $id)
    {
        $file = $request->file('image');
        #dump($file);
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $id.'.'.$extension;
        $file->move('img/', $filename);
    }


    private function getNextCandidateAuctions()
    {
        $now = Carbon::now();
        $auctions = Auction::
        whereNull('fruitless')
        ->whereNull('winner_user_id')
        ->with('product')
        ->with('product.category')
        ->with(['max_bid' => function($query){ $query->take(1); }])
        ->with('my_bid')
        ->whereDate('start_datetime', '>=', $now)
        ->withCount('bid')
        ->get()
        ->toArray();
        return $auctions;
    }


    public function auction()
    {
        #dump($this->getNextAuction($active_auction['id']));
        $active_auction = $this->getActiveAuction($this->getNextCandidateAuctions());
        return view('active-auction', 
        [
            'countdown' => empty($active_auction) ? false : true,
            'active_auction' => $active_auction,
            'next_auction' => empty($active_auction) ? $this->getNextAuction() : $this->getNextAuction($active_auction['id']),
        ]);
    }


    public function singleAuction(Request $request)
    {
        if ( empty($request->id) || !is_numeric($request->id) ){
            return redirect()->back();
        }
        $auctionId = (int) $request->id;
        $auction = Auction::where('id', $request->id)
        ->with('product')
        ->with('product.category')
        ->with(['max_bid' => function($query){ $query->take(1); }])
        ->with('my_bid')
        ->withCount('bid')
        ->first();
        $active_auction = $this->getActiveAuction($this->getNextCandidateAuctions(), $auctionId);
        #dump($active_auction);
        return view('single-auction', 
        [
            'not_found' => empty($auction) ? $auctionId : null,
            'is_active' => empty($active_auction) ? false : true,
            'countdown' => false,
            'auction' => $auction,
        ]);
    }


    private function getActiveAuction($auctions, $auctionId=null)
    {
        $active_auction = null;
        $now = Carbon::now();
        foreach($auctions as $auction){
            if ( $auctionId !== null && $auction['id'] !== $auctionId ){
                continue;
            }
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $auction['start_datetime'], 'Europe/Athens');
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $auction['end_datetime'], 'Europe/Athens');
            if ( $now->between($start, $end) ){
                #dump($auction);
                $active_auction = $auction;
            }
        }
        return $active_auction;
    }


    public function getNextAuction($notActiveAuctionId=null)
    {
        $now = Carbon::now();
        $query = Auction::query();
        $query->whereDate('start_datetime', '>=', $now)
        ->whereNull('fruitless')
        ->whereNull('winner_user_id');
        if ( $notActiveAuctionId !== null ){
            $query->where('id', '<>', $notActiveAuctionId);
        }
        $query->orderBy(DB::raw('ABS(DATEDIFF(start_datetime, NOW()))'));
        return $query->first();
    }


    public function bidAuction(Request $request)
    {
        $auctionId = (int) $request->input('id');
        $price = $request->input('price');
        if ( is_numeric($auctionId) && is_numeric($price) ){
            if ( $this->isBidForAuctionValid($auctionId) === false ){
                return response()->json(['status' => 'error', 'message' => 'Η δημοπρασία είναι παρελθοντική.']);  
            }
            $bid = new Bid();
            $bid->user_id = Auth::user()->id;
            $bid->price = (float) $price;
            $bid->auction_id = $auctionId;
            if ( $bid->save() ){
                return response()->json(['status' => 'success', 'message' => $this->getMaxBidByAuctionId($auctionId)]);   
            }
        }
    }


    public function updateAuction(Request $request)
    {
        $auctionId = (int) $request->input('id');
        if ( is_numeric($auctionId) ){
            if ( $message = $this->checkAuctionStatus($auctionId) ){
                if ( $message !== null ){
                    return response()->json(['status' => 'finish', 'message' => $message]);   
                }
            }
            return response()->json(['status' => 'success', 'message' => $this->getMaxBidByAuctionId($auctionId)]);   
        }
    }


    private function getMaxBidByAuctionId($auctionId)
    {
        return Auction::where('id', $auctionId)
        ->with(['max_bid' => function($query){ $query->take(1); }])
        ->with('my_bid')
        ->first();
    }


    private function checkAuctionStatus($auctionId)
    {
        $now = Carbon::now();
        $auction = Auction::where('id', $auctionId)
        ->with('latest_bid')
        ->with('max_bid')
        ->first();
        if ( !empty($auction) ){
            if ( !empty($auction['latest_bid'][0]['created_at']) ){
                $totalDurationSeconds = $now->diffInSeconds(Carbon::createFromFormat('Y-m-d H:i:s', $auction['latest_bid'][0]['created_at'], 'Europe/Athens'));
                if ( $totalDurationSeconds >= self::$noBidAuctionInSeconds ){
                    #WE HAVE A WINNER
                    if ( $auction['max_bid'][0]['price'] > $auction['opening_price'] ){
                        $auction['winner_user_id'] = $auction['max_bid'][0]['user_id'];
                        $auction['won'] = $now->format('Y-m-d H:i:s');
                        if ( $auction->save() ){
                            return 'Η δημοπρασία κατακυρώθηκε στο χρήστη #'.$auction['max_bid'][0]['user_id'];
                        }
                    } 
                    #FRUITLESS
                    else{
                        $auction['fruitless'] = $now->format('Y-m-d H:i:s');
                        if ( $auction->save() ){
                            return 'Η δημοπρασία είναι άγονη.';
                        } 
                    }
                }
            }
            $totalDurationSeconds = $now->diffInSeconds(Carbon::createFromFormat('Y-m-d H:i:s', $auction['start_datetime'], 'Europe/Athens'));
            #FRUITLESS
            if ( $totalDurationSeconds >= self::$completelyFruitelessAuctionInSeconds ){
                $auction['fruitless'] = $now->format('Y-m-d H:i:s');
                if ( $auction->save() ){
                    return 'Η δημοπρασία είναι άγονη.';
                }
            }
        }
        return null;
    }


    private function isBidForAuctionValid($auctionId)
    {
        $now = Carbon::now();
        $auction = Auction::where('id', $auctionId)
        ->whereDate('end_datetime', '<=', $now)
        ->whereNull('fruitless')
        ->whereNull('winner_user_id')
        ->first();
        return !empty($auction) ? true : false;
    }


    public function searchAuction(Request $request)
    {
        $searchManufacturer = $searchCategory = $searchQuery = '';
        $auctions = [];
        $products = Product::all();
        $manufacturers = [];
        if ( !empty($products) ){
            foreach($products as $p){
                $manufacturers[] = $p['manufacturer'];
            }
        }
        $manufacturers = array_unique($manufacturers);
        $method = $request->method();
        if ( $method == 'POST' ){
            $searchQuery = trim($request->input('query'));
            $query = Product::query();
            if ( !empty($searchQuery) ){
                $query->where(function ($query) use($searchQuery) {
                    $query
                    ->orWhere('name', 'LIKE', '%'.$searchQuery.'%')
                    ->orWhere('name', 'LIKE', $searchQuery.'%')
                    ->orWhere('manufacturer', 'LIKE', '%'.$searchQuery.'%')
                    ->orWhere('manufacturer', 'LIKE', $searchQuery.'%');
                });
            }
            if ( !empty($request->input('category_id')) ){
                $searchCategory = $request->input('category_id');
                $query->where('category_id', $searchCategory);
            }
            if ( !empty($request->input('manufacturer')) ){
                $searchManufacturer = $request->input('manufacturer');
                $query->where('manufacturer', $searchManufacturer);
            }
            $query
            ->with('auction')
            ->with('auction.product')
            ->with('auction.max_bid')
            ->with('auction.product.category');
            $products = $query->get()->toArray();
            if ( !empty($products) ){
                foreach($products as $p){
                    if ( !empty($p['auction']) ){
                        $auctions[] = $p['auction'];
                    }
                }
            }
        }
        #dump($auctions);
        return view('search-auction', 
        [
            'method' => $method,
            'search_query' => $searchQuery,
            'search_category' => $searchCategory,
            'search_manufacturer' => $searchManufacturer,
            'auctions' => $auctions,
            'manufacturers' => $manufacturers,
            'categories' => Category::all(),
        ]);
    }


    public function sendAuctionToUser(Request $request)
    {
        if ( is_numeric($request->input('id')) ){
            $auctionId = (int) $request->input('id');
            $auction = Auction::where('id', $auctionId)->first();
            if ( !empty($auction['sent_to_user']) ){
                return response()->json(['error' => 'error', 'message' => 'Το προϊόν της δημοπρασίας έχει ήδη σταλεί στο χρήστη']);
            }
            if ( !empty($auction['winner_user_id']) ){
                $now = Carbon::now();
                $auction['sent_to_user'] = $now->format('Y-m-d H:i:s');
                if ( $auction->save() ){
                    return response()->json(['status' => 'success', 'message' => 'Εστάλη επιτυχώς.']); 
                }
            }
        }
        return response()->json(['error' => 'error', 'message' => 'Συνέβη κάποιο λάθος με την αποστολή του προϊόντος της δημοπρασίας στο χρήστη.']); 
    }


    public function auctionReports()
    {
        if ( Auth::user()->role != 'admin' ){
            return redirect()->route('login');
        }
        $allAuctions = Auction::all();
        $fruitlessAuctions = Auction::whereNull('winner_user_id')
        ->with('product')
        ->with('max_bid')
        ->get()
        ->toArray();
        $fruitlessAuctionsCount = 'Άγονες δημοπρασίες: '.count($fruitlessAuctions);
        return view('auction-reports', 
        [
            'averageAuctionTime' => $this->getAverageAuctionTimeInSeconds($allAuctions),
            'fruitlessAuctionsCount' => $fruitlessAuctionsCount,
            'fruitlessAuctions' => $fruitlessAuctions,
        ]);
    }


    private function getAverageAuctionTimeInSeconds($allAuctions)
    {
        $auctionTimes = [];
        if ( !empty($allAuctions) ){
            foreach($allAuctions as $auction){
                $startDatetimeCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $auction['start_datetime'], 'Europe/Athens');
                if ( !empty($auction['won']) ){
                    $endCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $auction['won'], 'Europe/Athens');
                    $auctionTimes[] = $endCarbon->diffInSeconds($startDatetimeCarbon);
                } else if ( empty($auction['fruitless']) ){
                    $auctionTimes[] = $auction['auto_fruitless_seconds'];
                } else{
                    $endCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $auction['fruitless'], 'Europe/Athens');
                    $auctionTimes[] = $endCarbon->diffInSeconds($startDatetimeCarbon);
                }
            }
        }
        #dump($auctionTimes);
        $auctionTimes = array_filter($auctionTimes);
        if ( count($auctionTimes) ) {
            $averageAuctionTime = 'Μέσος χρόνος δημοπρασίας: '.number_format(array_sum($auctionTimes) / count($auctionTimes), 2).' δεπτερόλεπτα (από '.count($allAuctions).')';
        }
        return $averageAuctionTime;
    }


    /*public function mock()
    {
        $auctions = Auction::pluck('product_id');
        #dump($auctions);
        $products = Product::whereNotIn('id', $auctions)
        ->limit(1)
        ->get()
        ->toArray()
        ;
        if ( !empty($products) ){
            #dump($products);
            foreach($products as $product){
                $auction = new Auction();
                $auction->product_id = $product['id'];
                $auction->opening_price = $product['price'];
                $auction->start_datetime = date('Y-m-d H:i:s');
                $status = rand(0, 2);
                if ( $status === 0 ){
                    $duration = self::$completelyFruitelessAuctionInSeconds;
                } else if ( $status === 1 ){
                    $duration = rand(30, self::$completelyFruitelessAuctionInSeconds);
                    $bids = rand(1, 10);
                    for($i = 0; $i < $bids; $i++){
                        $auction->
                        $price = rand(0, 100);
                        $productPrice = (int) $product['price'];
                        if ( $price > $productPrice ){

                        }
                    }
                }
            }
        }
    }*/


    /*public function debug()
    {
        $start_datetime = '2021-07-11 20:45:00';
        $end_datetime = '2021-07-11 21:45:00';
        $conflictingAuction = Auction::
        whereNull('fruitless')
        ->whereNull('winner_user_id')
        ->where(function ($query) use($start_datetime, $end_datetime) {
            $query->whereBetween('start_datetime', [$start_datetime, $end_datetime])
                  ->orWhereBetween('end_datetime', [$start_datetime, $end_datetime]);
        })
        ->orWhereRaw('? BETWEEN start_datetime and end_datetime', [$start_datetime]) 
        ->orWhereRaw('? BETWEEN start_datetime and end_datetime', [$end_datetime])
        ->first();
        dump($conflictingAuction);
    }*/


}