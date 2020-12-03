<?php

namespace AppPay\Http\Controllers\Backend\BookingService;

use DateTime;
use Illuminate\Http\Request;
use AppPay\Http\Controllers\Controller;
use AppPay\Http\Controllers\Backend\Base;
use AppPay\Models\BookingService\Booking;

class DailySalesController extends Base
{
    public function index()
    {
      if(!$this->_user->hasPermission('bs_daily_sales.booking.view'))
      return abort('403');

        $booking_sums = Booking::leftJoin('mall_batch_logs', function($join) {
            $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
          })
        ->groupBy('bookings.operation_date')
        ->selectRaw('bookings.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id as batch_id, mall_batch_logs.updated_at as updated_at')
	->orderBy('bookings.operation_date', 'asc')
	->get()
        //->sortByDesc('bookings.operation_date');
        ->take(30);

        $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
            $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
          })
        ->groupBy('mall_batch_logs.operation_date')
        ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id as batch_id, mall_batch_logs.updated_at as updated_at')
  ->orderBy('mall_batch_logs.operation_date', 'asc')
  ->get()
        //->sortByDesc('bookings.operation_date');
        ->take(30);

        $booking_sums_new = [];
        $key_alt_last = -1;
        
        // while($today_date > $current_date){
          foreach($booking_sums as $key => $sum){
            foreach($booking_sums_alt as $key_alt => $sum_alt){
              if($sum->operation_date > $sum_alt->operation_date && $key_alt_last < $key_alt){
                array_push($booking_sums_new, $sum_alt);
                $key_alt_last = $key_alt;
              }
            }
            array_push($booking_sums_new, $sum);
            if($key+1 == count($booking_sums)){
              foreach($booking_sums_alt as $key_alt => $sum_alt){
                if($key_alt_last < $key_alt){
                  array_push($booking_sums_new, $sum_alt);
                  $key_alt_last = $key_alt;
                }
              }
            }
          }
          if(!count($booking_sums)){
            $booking_sums_new = $booking_sums_alt;
          }
        // }

        $booking_sums = $booking_sums_new;

        return view('booking_service.daily_sales.index')->with([
            'booking_sums' => $booking_sums
        ]);
    }

    public function details($sales_date)
    {
      if(!$this->_user->hasPermission('bs_daily_sales.booking.view'))
      return abort('403');

      $kiosks = Booking::where('operation_date','=', $sales_date)->distinct()->get(['kiosk_id']);
      $daily_sales_byKiosk = Booking::where('operation_date','=', $sales_date)->groupBy('kiosk_id')
                                  ->selectRaw('kiosk_id, sum(payment_amount) as total_payment')->orderBy('kiosk_id')->get();

      $daily_sales_detail = Booking::where('operation_date','=', $sales_date)->groupBy('kiosk_id', 'payment_mode')
                                ->selectRaw('kiosk_id, payment_mode, sum(payment_amount) as total_payment')->orderBy('kiosk_id')->orderBy('payment_mode')->get();

      //dd($daily_sales_byKiosk);

      $daily_payment_amount = Booking::where('operation_date', '=', $sales_date)->sum('payment_amount');
      $daily_payment_count = Booking::where('operation_date', '=', $sales_date)->count();

      //$daily_cash_payment_amount = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','Cash')->sum('payment_amount');
      //$daily_cash_payment_count = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','Cash')->count();

      //$daily_cc_payment_amount = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','Credit Card')->sum('payment_amount');
      //$daily_cc_payment_count = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','Credit Card')->count();

      //$daily_tng_payment_amount = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','TNG')->sum('payment_amount');
      //$daily_tng_payment_count = Booking::where('operation_date', '=', $sales_date)->where('payment_mode','=','TNG')->count();

        return view('booking_service.daily_sales.details')->with([
            'daily_sales_byKiosk' => $daily_sales_byKiosk,
            'daily_sales_detail' => $daily_sales_detail,
            'sales_date' => $sales_date,
            'daily_payment_amount' => $daily_payment_amount,
            'daily_payment_count' => $daily_payment_count
            // 'daily_cash_payment_amount' => $daily_cash_payment_amount,
            // 'daily_cash_payment_count' => $daily_cash_payment_count,
            // 'daily_cc_payment_amount' => $daily_cc_payment_amount,
            // 'daily_cc_payment_count' => $daily_cc_payment_count,
            // 'daily_tng_payment_amount' => $daily_tng_payment_amount,
            // 'daily_tng_payment_count' => $daily_tng_payment_count
        ]);
    }

    public function search(Request $request)
    {
      if(!$this->_user->hasPermission('bs_daily_sales.booking.view'))
      return abort('403');
      
        if(!empty($request->daterange))
        {
          $dateArray = explode('-', $request->daterange);
          $dateFrom = DateTime::createFromFormat('d/m/Y', str_replace(' ', '',$dateArray[0]));
          $dateTo = DateTime::createFromFormat('d/m/Y', str_replace(' ', '',$dateArray[1]));
    
          if($dateFrom->format('Y-m-d') == $dateTo->format('Y-m-d'))
          {
            $booking_sums = Booking::leftJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('bookings.operation_date')
            ->where('bookings.operation_date','=',$dateFrom->format('Y-m-d'))
            ->selectRaw('bookings.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->get();

            $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('mall_batch_logs.operation_date')
            ->where('mall_batch_logs.operation_date','=',$dateFrom->format('Y-m-d'))
            ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->get();
          }
          else
          {
            $booking_sums = Booking::leftJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('bookings.operation_date')
            ->whereBetween('bookings.operation_date',[$dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')])
            ->selectRaw('bookings.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->get();

            $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('mall_batch_logs.operation_date')
            ->whereBetween('mall_batch_logs.operation_date',[$dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')])
            ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->get();
          }

          $booking_sums_new = [];
          $key_alt_last = -1;
          
          // while($today_date > $current_date){
            foreach($booking_sums as $key => $sum){
              foreach($booking_sums_alt as $key_alt => $sum_alt){
                if($sum->operation_date > $sum_alt->operation_date && $key_alt_last < $key_alt){
                  array_push($booking_sums_new, $sum_alt);
                  $key_alt_last = $key_alt;
                }
              }
              array_push($booking_sums_new, $sum);
              if($key+1 == count($booking_sums)){
                foreach($booking_sums_alt as $key_alt => $sum_alt){
                  if($key_alt_last < $key_alt){
                    array_push($booking_sums_new, $sum_alt);
                    $key_alt_last = $key_alt;
                  }
                }
              }
            }
            if(!count($booking_sums)){
              $booking_sums_new = $booking_sums_alt;
            }
          // }
  
          $booking_sums = $booking_sums_new;

          return view('booking_service.daily_sales.index')->with([
            'booking_sums' => $booking_sums,
            'daterange' => $request->daterange
            ]);
        }
    }
}
