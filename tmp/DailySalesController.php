<?php

namespace AppPay\Http\Controllers\Backend\BookingService;

use DateTime;
use Illuminate\Http\Request;
use AppPay\Http\Controllers\Controller;
use AppPay\Http\Controllers\Backend\Base;
use AppPay\Models\BookingService\Booking;
use AppPay\Models\BookingService\SysParameter;

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
	->orderBy('bookings.operation_date', 'desc')
	->get()
        //->sortByDesc('bookings.operation_date');
        ->take(30);

        $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
            $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
          })
        ->groupBy('mall_batch_logs.operation_date')
        ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id as batch_id, mall_batch_logs.updated_at as updated_at')
  ->orderBy('mall_batch_logs.operation_date', 'desc')
  ->get()
        //->sortByDesc('bookings.operation_date');
        ->take(30);

        // note: $booking_sums is sorted in descending order

        $booking_sums_new = [];
        $outlet = SysParameter::first();
        $operation_date_first = date('Y-m-d');
        $operation_date_last = date('Y-m-d', strtotime($outlet->created_at ?? ( $booking_sums[count($booking_sums)-1]->operation_date ?? '2019-01-01' )));
        $operation_date_prev =  date('Y-m-d', strtotime('+1 day', strtotime($operation_date_first)));

        // // prevent overflow and underflow
        // if($operation_date_first > date('Y-m-d')){
        //   $operation_date_first = date('Y-m-d');
        // }
        // if($outlet->created_at && $operation_date_last < strtotime($outlet->created_at)){
        //   $operation_date_last = $outlet->created_at;
        // }

        if(!count($booking_sums)){
          // no bookings, fallback to mall_batch_logs
          $booking_sums = $booking_sums_alt;
          if(!count($booking_sums_alt)){
            // no bookings and mall_batch_logs, push a dummy data to trigger the flow
            $booking_sums = [
              0 => (object)[
                'operation_date' => $operation_date_first,
                'total_payment'  => 0.00,
                'total_booking'  => 0,
                'batch_id'       => null,
                'updated_at'     => null,
              ]
            ];
          }
        }

        // loop all bookings
        foreach($booking_sums as $key => $sum){

          // check for empty bookings before first booking or subsequent bookings
          if(($key == 0 && $operation_date_first > $sum->operation_date) || ($key && $operation_date_prev > $sum->operation_date)){
            // if target operation_date is larger than booking operation_date, do something
            while(date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev))) > $sum->operation_date){
              $operation_date_current = date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev)));
              $sum_tmp = null;
              // loop all mall_batch_logs
              foreach($booking_sums_alt as $key_alt => $sum_alt){
                // if mall_batch_log operation_date is same as target operation_date, use this mall_batch_log
                if($sum_alt->operation_date == $operation_date_current){
                  $sum_tmp = $sum_alt;
                  break 1;
                }
              }
              // if no matching mall_batch_log, create empty record manually
              if(is_null($sum_tmp)){
                $sum_tmp = (object)[
                  'operation_date' => $operation_date_current,
                  'total_payment'  => 0.00,
                  'total_booking'  => 0,
                  'batch_id'       => null,
                  'updated_at'     => null,
                ];
              }
              // push mall_batch_logs to array
              array_push($booking_sums_new, $sum_tmp);
              $operation_date_prev = $operation_date_current;
            }
          }

          // push booking to array
          array_push($booking_sums_new, $sum);
          $operation_date_prev = $sum->operation_date;

          // check for empty booking after last booking
          if($key+1 == count($booking_sums) && $sum->operation_date > $operation_date_last){
            while(date('Y-m-d', strtotime($operation_date_prev)) > $operation_date_last){
              $operation_date_current = date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev)));
              $sum_tmp = null;
              foreach($booking_sums_alt as $key_alt => $sum_alt){
                if($sum_alt->operation_date == $operation_date_current){
                  $sum_tmp = $sum_alt;
                  break 1;
                }
              }
              if(!$sum_tmp){
                $sum_tmp = (object)[
                  'operation_date' => $operation_date_current,
                  'total_payment'  => 0.00,
                  'total_booking'  => 0,
                  'batch_id'       => null,
                  'updated_at'     => null,
                ];
              }
              array_push($booking_sums_new, $sum_tmp);
              $operation_date_prev = $operation_date_current;
            }
          }
        }

        // // if no bookings, fallback to mall_batch_logs
        // if(!count($booking_sums)){
        //   $booking_sums_new = $booking_sums_alt;
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
            ->orderBy('bookings.operation_date', 'desc')
            ->get();

            $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('mall_batch_logs.operation_date')
            ->where('mall_batch_logs.operation_date','=',$dateFrom->format('Y-m-d'))
            ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->orderBy('mall_batch_logs.operation_date', 'desc')
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
            ->orderBy('bookings.operation_date', 'desc')
            ->get();

            $booking_sums_alt = Booking::rightJoin('mall_batch_logs', function($join) {
                $join->on('bookings.operation_date', '=', 'mall_batch_logs.operation_date');
              })
            ->groupBy('mall_batch_logs.operation_date')
            ->whereBetween('mall_batch_logs.operation_date',[$dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')])
            ->selectRaw('mall_batch_logs.operation_date, sum(payment_amount) as total_payment, count(*) as total_booking, mall_batch_logs.batch_id, mall_batch_logs.updated_at')
            ->orderBy('mall_batch_logs.operation_date', 'desc')
            ->get();
          }

          // note: $booking_sums is sorted in descending order

          $booking_sums_new = [];
          $outlet = SysParameter::first();
          $operation_date_first = date('Y-m-d', strtotime($dateTo->format('Y-m-d')));
          $operation_date_last = date('Y-m-d', strtotime($dateFrom->format('Y-m-d')));
          $operation_date_prev =  date('Y-m-d', strtotime('+1 day', strtotime($operation_date_first)));

          // // prevent overflow and underflow
          // if($operation_date_first > date('Y-m-d')){
          //   $operation_date_first = date('Y-m-d');
          // }
          // if($outlet->created_at && $operation_date_last < strtotime($outlet->created_at)){
          //   $operation_date_last = $outlet->created_at;
          // }

          if(!count($booking_sums)){
            // no bookings, fallback to mall_batch_logs
            $booking_sums = $booking_sums_alt;
            if(!count($booking_sums_alt)){
              // no bookings and mall_batch_logs, push a dummy data to trigger the flow
              $booking_sums = [
                0 => (object)[
                  'operation_date' => $operation_date_first,
                  'total_payment'  => 0.00,
                  'total_booking'  => 0,
                  'batch_id'       => null,
                  'updated_at'     => null,
                ]
              ];
            }
          }

          // loop all bookings
          foreach($booking_sums as $key => $sum){

            // check for empty bookings before first booking or subsequent bookings
            if(($key == 0 && $operation_date_first > $sum->operation_date) || ($key && $operation_date_prev > $sum->operation_date)){
              // if target operation_date is larger than booking operation_date, do something
              while(date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev))) > $sum->operation_date){
                $operation_date_current = date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev)));
                $sum_tmp = null;
                // loop all mall_batch_logs
                foreach($booking_sums_alt as $key_alt => $sum_alt){
                  // if mall_batch_log operation_date is same as target operation_date, use this mall_batch_log
                  if($sum_alt->operation_date == $operation_date_current){
                    $sum_tmp = $sum_alt;
                    break 1;
                  }
                }
                // if no matching mall_batch_log, create empty record manually
                if(is_null($sum_tmp)){
                  $sum_tmp = (object)[
                    'operation_date' => $operation_date_current,
                    'total_payment'  => 0.00,
                    'total_booking'  => 0,
                    'batch_id'       => null,
                    'updated_at'     => null,
                  ];
                }
                // push mall_batch_logs to array
                array_push($booking_sums_new, $sum_tmp);
                $operation_date_prev = $operation_date_current;
              }
            }

            // push booking to array
            array_push($booking_sums_new, $sum);
            $operation_date_prev = $sum->operation_date;

            // check for empty booking after last booking
            if($key+1 == count($booking_sums) && $sum->operation_date > $operation_date_last){
              while(date('Y-m-d', strtotime($operation_date_prev)) > $operation_date_last){
                $operation_date_current = date('Y-m-d', strtotime('-1 day', strtotime($operation_date_prev)));
                $sum_tmp = null;
                foreach($booking_sums_alt as $key_alt => $sum_alt){
                  if($sum_alt->operation_date == $operation_date_current){
                    $sum_tmp = $sum_alt;
                    break 1;
                  }
                }
                if(!$sum_tmp){
                  $sum_tmp = (object)[
                    'operation_date' => $operation_date_current,
                    'total_payment'  => 0.00,
                    'total_booking'  => 0,
                    'batch_id'       => null,
                    'updated_at'     => null,
                  ];
                }
                array_push($booking_sums_new, $sum_tmp);
                $operation_date_prev = $operation_date_current;
              }
            }
          }

          // // if no bookings, fallback to mall_batch_logs
          // if(!count($booking_sums)){
          //   $booking_sums_new = $booking_sums_alt;
          // }

          $booking_sums = $booking_sums_new;

          return view('booking_service.daily_sales.index')->with([
            'booking_sums' => $booking_sums,
            'daterange' => $request->daterange
            ]);
        }
    }
}
