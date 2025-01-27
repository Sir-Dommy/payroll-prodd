<?php

namespace App\Http\Controllers;

use App\Exports\DisbursedLoanListingExport;
use App\Exports\MemberExport;
use App\Exports\NhifReport;
use App\Exports\NssfReport;
use App\Exports\PayeReport;
use App\Exports\PayrollExport;
use Maatwebsite\Excel\Concerns\Exportable;
use App\Exports\P9FormExports;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Audit;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Jobgroup;
use App\Models\Journal;
use App\Models\Loanaccount;
use App\Models\Loanproduct;
use App\Models\Loanrepayment;
use App\Models\Loantransaction;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Particular;
use App\Models\Payroll;
use App\Models\Pensioninterest;
use App\Models\Property;
use App\Models\Savingaccount;
use App\Models\Savingproduct;
use App\Models\Transact;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Spreadsheet;


class ReportsController extends Controller
{
    use Exportable;

    private $excel;

    public function __construct(Excel $excel)
    {
        $this->excel = $excel;
    }


    public function members()
    {
        return view('members.members_select');
    }

    public function membersListing(Request $request)
    {
        $validator = Validator::make($request->all(), array(
            'type' => 'required|in:all,active,inactive'
        ));

        if ($validator->fails()) {
            return Redirect()->back()->withErrors($validator)->withInput();
        }

        $type = $request->get('type');
        switch ($type) {
            case 'all':
                $members = Member::all();
                break;

            case 'active':
                $members = Member::where('is_active', 1)->get();
                break;

            case 'inactive':
                $members = Member::where('is_active', 0)->get();
                break;

            default:
                $members = Member::all();
                break;
        }

        $organization = Organization::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        //return $members;
        if ($request->get('format') == 'pdf') {
            $pdf = app('dompdf.wrapper')->loadView('pdf.memberlist', compact('members', 'organization'))->setPaper('a4');

            return $pdf->stream('MemberList.pdf');

        } elseif ($request->get('format') == 'excel') {

            return (new MemberExport())->download('MemberList.xlsx');

        }


    }


    public function remittance()
    {

        //$members = DB::table('members')->where('is_active', '=', '1')->get();

        $members = Member::all();
        $organization = Organization::find(1);

        $savingproducts = Savingproduct::all();

        $loanproducts = Loanproduct::all();

        $pdf = app('dompdf.wrapper')->loadView('pdf.remittance', compact('members', 'organization', 'loanproducts', 'savingproducts'))->setPaper('a4', 'landscape');

        return $pdf->stream('Remittance.pdf');

    }


    public function template()
    {

        $members = Organization::all();

        $organization = Organization::find(Auth::user()->organization_id);

        $pdf = app('dompdf.wrapper')->loadView('pdf.blank', compact('members', 'organization'))->setPaper('a4', 'landscape');

        return $pdf->stream('Template.pdf');

    }


    public function loanlisting($data)
    {

        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
            $to = $data['date'];
        }

        if ($data['period'] == 'As at date') {
            $loans = Loanaccount::where('is_disbursed', true)->where('date_disbursed', '<=', $data['date'])->get();
        } else {
            $loans = Loanaccount::where('is_disbursed', true)->whereBetween('date_disbursed', array($from, $to))->get();
        }

        $organization = Organization::find(1);

        if ($data['format'] == 'excel') {

            return (new DisbursedLoanListingExport($data, $period))->download('DisbursedLoans.xlsx');

        } else {
            $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.loanbalances', compact('loans', 'organization', 'to', 'period'))->setPaper('a4');

            return $pdf->stream('Loan Listing.pdf');
        }


    }


    public static function loanproductReport($data)
    {

        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
            $to = $data['date'];
        }

        $loanproduct = Loanproduct::find($data['loanproduct_id']);

        if ($data['period'] == 'As at date') {
            $loans = Loanaccount::where('loanproduct_id', $loanproduct->id)->where('is_disbursed', true)->where('date_disbursed', '<=', $data['date'])->get();
        } else {
            $loans = Loanaccount::where('loanproduct_id', $loanproduct->id)->where('is_disbursed', true)->whereBetween('date_disbursed', array($from, $to))->get();
        }

        $organization = Organization::find(1);
        if ($data['format'] == 'excel') {
            return Excel::create($loanproduct->name, function ($excel) use ($loans, $period, $to, $loanproduct) {
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                $excel->sheet('DisbursedLoans', function ($sheet) use ($loans, $period, $to, $loanproduct) {
                    $sheet->setAllBorders('thin');

                    $sheet->setWidth(array("A" => 30, "B" => 50, "C" => 25, "D" => 20, "E" => 20, "F" => 20, "G" => 20, "H" => 20));
                    $sheet->mergeCells('A1:E1');
                    $sheet->row(1, array($loanproduct->name . " Report " . $period));

                    $sheet->cells('A1:F1', function ($cells) {
                        $cells->setFont(array('bold' => true));
                        $cells->setAlignment('center');
                    });

                    $sheet->row(2, array("Membership No", "Member name", "Loan product", "Loan Number", "Loan Amount", "Principal", "Interest", "Loan Balance"));

                    $sheet->cells('A2:H2', function ($cells) {
                        $cells->setFont(array(
                            'bold' => true
                        ));
                    });

                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    $row = 3;
                    $total = 0;
                    $totalBal = 0;
                    $totalDisbursed = 0;
                    $totalBalance = 0;
                    $totalInterest = 0;
                    $totalPrinc = 0;
                    foreach ($loans as $loan) {
                        if (Loantransaction::getLoanBalance($loan) > 1) {
                            $princBalance = Loanaccount::getPrincipalBal($loan, $to);
                            $interestBal = Loanaccount::getInterestBal($loan, $to);
                            $loanBalance = Loantransaction::getLoanBalanceAt($loan, $to);
                            $sheet->cell('A' . $row, function ($cell) use ($loan) {
                                $cell->setValue($loan->member->membership_no);
                            });

                            $sheet->cell('B' . $row, function ($cell) use ($loan) {
                                $cell->setValue($loan->member->name);
                            });

                            $sheet->cell('C' . $row, function ($cell) use ($loan) {
                                $cell->setValue($loan->loanproduct->name);
                            });

                            $sheet->cell('D' . $row, function ($cell) use ($loan) {
                                $cell->setValue($loan->account_number);
                            });

                            $sheet->cell('E' . $row, function ($cell) use ($loan) {
                                $cell->setValue(number_format(Loanaccount::getActualAmount($loan), 2));
                            });
                            $sheet->cell('F' . $row, function ($cell) use ($loan, $princBalance) {
                                $cell->setValue(number_format($princBalance, 2));
                            });
                            $sheet->cell('G' . $row, function ($cell) use ($loan, $interestBal) {
                                $cell->setValue(number_format($interestBal, 2));
                            });
                            $sheet->cell('H' . $row, function ($cell) use ($loan, $to) {
                                $cell->setValue(number_format(Loantransaction::getLoanBalanceAt($loan, $to), 2));
                            });

                            $row++;
                            $total += Loanaccount::getActualAmount($loan);
                            $totalBal += Loantransaction::getLoanBalanceAt($loan, $to);
                            $totalDisbursed += $loan->amount_disbursed;
                            $totalBalance += $loanBalance;
                            $totalInterest += $interestBal;
                            $totalPrinc += $princBalance;
                        }

                    }

                    $sheet->mergeCells('A' . $row . ':D' . $row);
                    $sheet->cells('A' . $row . ':D' . $row, function ($cells) {
                        $cells->setFont(array('bold' => true));
                        $cells->setAlignment('center');
                    });

                    $sheet->row($row, array('Total:'));
                    $sheet->cell('E' . $row, function ($cell) use ($total) {
                        $cell->setValue(number_format($total, 2));
                        $cell->setFont(array('bold' => true));
                    });
                    $sheet->cell('F' . $row, function ($cell) use ($totalPrinc) {
                        $cell->setValue(number_format($totalPrinc, 2));
                        $cell->setFont(array('bold' => true));
                    });
                    $sheet->cell('G' . $row, function ($cell) use ($totalInterest) {
                        $cell->setValue(number_format($totalInterest, 2));
                        $cell->setFont(array('bold' => true));
                    });
                    $sheet->cell('H' . $row, function ($cell) use ($totalBal) {
                        $cell->setValue(number_format($totalBal, 2));
                        $cell->setFont(array('bold' => true));
                    });
                });
            })->export('xlsx');
        } else {
            $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.loanproducts', compact('loans', 'loanproduct', 'organization', 'to', 'period'))->setPaper('a4');

            return $pdf->stream('Loan Product Listing.pdf');
        }


    }

    public function intercept(Request $request)
    {
        $data = $request->all();


        if ($data['loanproduct_id'] == 'all') {
            if ($data['type'] == 'interest') {
                return self::allInterestReport($data);
            } elseif ($data['type'] == 'savings') {
                return self::allsavingsReport($data);
            } else {
                return self::loanlisting($data);
            }
        } else {
            if ($data['type'] == 'interest') {
                return self::interestReport($data);
            } elseif ($data['type'] == 'savings') {
                return self::savingsReport($data);
            } elseif ($data['type'] == 'financials') {
                return self::financials($data);
            } else {
                return self::loanproductReport($data);
            }
        }
    }

    public function interest($id)
    {
        $type = 'interest';
        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public function loanproduct($id)
    {
        $type = 'loans';

        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public static function interestReport($data)
    {

        //prepare date range for year and month selection
        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
        }

        $loanproduct = Loanproduct::find($data['loanproduct_id']);

        $loans = Loanaccount::where('loanproduct_id', $loanproduct->id)->where('is_disbursed', true)->lists('id');

        if ($data['period'] == 'As at date') {
            $interests = Loanrepayment::whereIn('loanaccount_id', $loans)->where('date', '<=', $data['date'])->get();
        } else {
            $interests = Loanrepayment::whereIn('loanaccount_id', $loans)->whereBetween('date', array($from, $to))->get();
        }
        $intArray = array();

        foreach ($interests as $interest) {
            // code...
            $member = $interest->loanaccount->member;
            if (!key_exists($member->id, $intArray)) {
                $intArray[$member->id] = array(
                    'member_no' => $member->membership_no,
                    'member_name' => $member->name,
                    'loan_number' => $interest->loanaccount->account_number,
                    'totalInt' => $interest->interest_paid
                );
            } else {

                $intArray[$member->id]['totalInt'] += $interest->interest_paid;
            }


        }

        //return $intArray;
        $organization = Organization::find(1);

        if ($data['format'] == 'excel') {
            return Excel::create($loanproduct->name, function ($excel) use ($intArray, $period, $loanproduct) {
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                $excel->sheet('Interests', function ($sheet) use ($intArray, $period, $loanproduct) {
                    $sheet->setAllBorders('thin');

                    $sheet->setWidth(array("A" => 30, "B" => 50, "C" => 20));
                    $sheet->mergeCells('A1:C1');
                    $sheet->row(1, array($loanproduct->name . " Interest Report " . $period));

                    $sheet->cells('A1:C1', function ($cells) {
                        $cells->setFont(array('bold' => true));
                        $cells->setAlignment('center');
                    });

                    $sheet->row(2, array("Membership No", "Member name", "Interest Amount"));

                    $sheet->cells('A2:D2', function ($cells) {
                        $cells->setFont(array(
                            'bold' => true
                        ));
                    });

                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    $row = 3;
                    $total = 0;

                    foreach ($intArray as $interest) {
                        $sheet->cell('A' . $row, function ($cell) use ($interest) {
                            $cell->setValue($interest['member_no']);
                        });

                        $sheet->cell('B' . $row, function ($cell) use ($interest) {
                            $cell->setValue($interest['member_name']);
                        });

                        $sheet->cell('C' . $row, function ($cell) use ($interest) {
                            $cell->setValue(number_format($interest['totalInt'], 2));
                        });

                        $row++;
                        $total += $interest['totalInt'];
                    }

                    $sheet->mergeCells('A' . $row . ':B' . $row);
                    $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                        $cells->setFont(array('bold' => true));
                    });

                    $sheet->row($row, array('Total:'));
                    $sheet->cell('C' . $row, function ($cell) use ($total) {
                        $cell->setValue(number_format($total, 2));
                        $cell->setFont(array('bold' => true));
                    });
                });
            })->export('xlsx');
        } else {
            $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.interest', compact('intArray', 'loanproduct', 'organization', 'period'))->setPaper('a4');

            return $pdf->stream($loanproduct->name . '_interest.pdf');
        }


    }

    public function totalInterest()
    {
        $id = 'all';
        $type = 'interest';
        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public function allListing()
    {
        $id = 'all';
        $type = 'loan';
        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public function allInterestReport($data)
    {

        $all = true;

        //prepare date range for year and month selection
        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
        }

        if ($data['period'] == 'As at date') {
            $interests = Loanrepayment::where('date', '<=', $data['date'])->get();
        } else {
            $interests = Loanrepayment::whereBetween('date', array($from, $to))->get();
        }

        $intArray = array();

        foreach ($interests as $interest) {
            // code...
            if (isset($interest)) {
                $member = $interest->loanaccount->member;

                if (!key_exists($member->id, $intArray)) {
                    $intArray[$member->id] = array(
                        'member_no' => $member->membership_no,
                        'member_name' => $member->name,
                        'totalInt' => $interest->interest_paid
                    );

                } else {

                    $intArray[$member->id]['totalInt'] += $interest->interest_paid;

                }
            }
        }

        //return $intArray;

        $organization = Organization::find(1);


        if ($data['format'] == 'excel') {
            return Excel::create('total_interests', function ($excel) use ($intArray, $period) {
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                $excel->sheet('Interests', function ($sheet) use ($intArray, $period) {
                    $sheet->setAllBorders('thin');

                    $sheet->setWidth(array("A" => 30, "B" => 50, "C" => 20));
                    $sheet->mergeCells('A1:C1');
                    $sheet->row(1, array("Interest Report " . $period));

                    $sheet->cells('A1:C1', function ($cells) {
                        $cells->setFont(array('bold' => true));
                        $cells->setAlignment('center');
                    });

                    $sheet->row(2, array("Membership No", "Member name", "Interest Amount"));

                    $sheet->cells('A2:D2', function ($cells) {
                        $cells->setFont(array(
                            'bold' => true
                        ));
                    });

                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    $row = 3;
                    $total = 0;

                    foreach ($intArray as $interest) {
                        $sheet->cell('A' . $row, function ($cell) use ($interest) {
                            $cell->setValue($interest['member_no']);
                        });

                        $sheet->cell('B' . $row, function ($cell) use ($interest) {
                            $cell->setValue($interest['member_name']);
                        });

                        $sheet->cell('C' . $row, function ($cell) use ($interest) {
                            $cell->setValue(number_format($interest['totalInt'], 2));
                        });

                        $row++;
                        $total += $interest['totalInt'];
                    }

                    $sheet->mergeCells('A' . $row . ':B' . $row);
                    $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                        $cells->setFont(array('bold' => true));
                    });

                    $sheet->row($row, array('Total:'));
                    $sheet->cell('C' . $row, function ($cell) use ($total) {
                        $cell->setValue(number_format($total, 2));
                        $cell->setFont(array('bold' => true));
                    });
                });
            })->export('xlsx');
        } else {
            $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.interest', compact('intArray', 'all', 'organization', 'period'))->setPaper('a4');

            return $pdf->stream('Total_interest.pdf');
        }

    }

    public function loanrepayments(Request $request)
    {

        $data = $request->all();

        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
        }

        if ($data['loanproduct'] == 'All') {
            $loans = Loanaccount::where('is_disbursed', 1)->lists('id');

            if ($data['period'] == 'As at date') {
                $loantransactions = Loanrepayment::whereIn('loanaccount_id', $loans)->where('date', '<=', $data['date'])->get();

            } else {
                $loantransactions = Loanrepayment::whereIn('loanaccount_id', $loans)->whereBetween('date', array($from, $to))->get();

            }
            $all = true;

        } else {
            $loans = Loanaccount::where('loanproduct_id', $data['loanproduct'])->where('is_disbursed', 1)->lists('id');
            if ($data['period'] == 'As at date') {
                $loantransactions = Loanrepayment::whereIn('loanaccount_id', $loans)->where('date', '<=', $data['date'])->get();

            } else {
                $loantransactions = Loanrepayment::whereIn('loanaccount_id', $loans)->whereBetween('date', array($from, $to))->get();

            }
            $all = false;

            $loanproduct = Loanproduct::find($data['loanproduct']);
        }

        $loantrans = array();
        //return $loantransactions;

        foreach ($loantransactions as $trans) {
            if (!key_exists($trans->loanaccount->account_number, $loantrans)) {

                $loantrans[$trans->loanaccount->account_number] = array('member_no' => $trans->loanaccount->member->membership_no,
                    'member_name' => $trans->loanaccount->member->name,
                    'total' => $trans->principal_paid);

            } else {
                $loantrans[$trans->loanaccount->account_number]['total'] += $trans->principal_paid;


            }
        }
        $loanproduct = Loanproduct::find($data['loanproduct']);


        $organization = Organization::find(1);

        $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.repayments', compact('organization', 'period', 'loantrans', 'all', 'loanproduct'))->setPaper('a4');

        return $pdf->stream('Repayments.pdf');
    }


    public function savinglisting()
    {
        $id = 'all';
        $type = 'savings';

        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public static function allsavingsReport($data)
    {

        $savingaccounts = Savingaccount::all();

        //prepare date range for year and month selection
        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
        }

        $savings = array();

        foreach ($savingaccounts as $savingaccount) {
            // code...
            if ($data['period'] == 'As at date') {
                $balance = Savingaccount::getSavingsAsAt($savingaccount, $data['date']);
            } else {
                $balance = Savingaccount::getSavingsBetween($savingaccount, $from, $to);
            }
            $arr = array('member_no' => $savingaccount->member->membership_no,
                'member_name' => $savingaccount->member->name,
                'product_name' => $savingaccount->savingproduct->name,
                'account_no' => $savingaccount->account_number,
                'balance' => $balance
            );
            array_push($savings, $arr);


        }
        $organization = Organization::find(1);

        if ($data['format'] == 'pdf') {

            $pdf = app('dompdf.wrapper')->loadView('pdf.savingreports.savingbalances', compact('savings', 'organization', 'period'))->setPaper('a4', 'portrait');

            return $pdf->stream('Savings Listing.pdf');
        } else {//Displays in Excel format

            return Excel::create('Savings Report', function ($excel) use ($savings, $period) {

                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");


                $excel->sheet('Savings Report', function ($sheet) use ($savings, $period) {

                    $sheet->setAllBorders('thin');

                    $sheet->setWidth(array(
                        'A' => 15,
                        'B' => 40,
                        'C' => 15,
                        'D' => 20,
                        'E' => 15
                    ));

                    $sheet->mergeCells('A1:E1');

                    $sheet->row(1, array("Savings Listing Report " . $period));

                    $sheet->cells('A1:E1', function ($cells) {
                        $cells->setAlignment('center');
                        // $cells->setBackground('#777777');
                        $cells->setFont(array(
                            'family' => 'Calibri',
                            'bold' => true
                        ));
                    });

                    $sheet->mergeCells('A2:E2');


                    $sheet->row(3, array(
                        "Member", "Member Name", "Saving Product", "Account Number", "Account Balance"
                    ));

                    $sheet->cells('A3:E3', function ($cells) {
                        $cells->setFont(array(
                            'bold' => true
                        ));
                    });

                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    $row = 4;
                    $total = 0;
                    foreach ($savings as $saving) {
                        $sheet->cell('A' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['member_no']);
                        });
                        $sheet->cell('B' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['member_name']);
                        });
                        $sheet->cell('C' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['product_name']);
                        });
                        $sheet->cell('D' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['account_no']);
                        });
                        $sheet->cell('E' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['balance']);
                        });

                        $row++;
                        $total += $saving['balance'];
                    }

                    $sheet->mergeCells('A' . $row . ':D' . $row);
                    $sheet->cells('A' . $row . ':D' . $row, function ($cells) {
                        $cells->setFont(array('bold' => true));
                    });

                    $sheet->row($row, array('Total:'));
                    $sheet->cell('E' . $row, function ($cell) use ($total) {
                        $cell->setValue(number_format($total, 2));
                        $cell->setFont(array('bold' => true));
                    });

                });

            })->export('xlsx');

        }


    }


    public function savingproduct($id)
    {
        $type = 'savings';
        return view('pdf.selectperiod', compact('id', 'type'));
    }

    public static function savingsReport($data)
    {

        $savingproduct = Savingproduct::find($data['savingaccount_id']);

        $savingaccounts = $savingproduct->savingaccounts;
        //prepare date range for year and month selection
        if ($data['period'] == 'month') {
            $month = $data['month'];
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            $period = 'for ' . date('F Y', strtotime($from));
        } elseif ($data['period'] == 'year') {
            $year = $data['year'];
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            $period = 'for ' . $year;
        } elseif ($data['period'] == 'custom') {
            $from = $data['from'];
            $to = $data['to'];
            $period = 'for ' . date('d-M-Y', strtotime($from)) . ' to ' . date('d-M-Y', strtotime($to));
        } else {
            $period = 'as at ' . $data['date'];
        }

        $savings = array();

        foreach ($savingaccounts as $savingaccount) {
            // code...
            if ($data['period'] == 'As at date') {
                $balance = Savingaccount::getSavingsAsAt($savingaccount, $data['date']);
            } else {
                $balance = Savingaccount::getSavingsBetween($savingaccount, $from, $to);
            }

            /* $arr = array('member_no' => $savingaccount->member->membership_no,
            'member_name' => $savingaccount->member->name,
            'product_name' => $savingaccount->savingproduct->name,
            'account_no' => $savingaccount->account_number,
            'balance' => $balance
            );

            array_push($savings, $arr);
            }**/
            $arr = array('member_no' => $savingaccount->member->membership_no,
                'member_name' => $savingaccount->member->name,
                'product_name' => $savingaccount->savingproduct->name,
                'account_no' => $savingaccount->account_number,
                'balance' => $balance,
                'interest_amount' => $balance * ($savingaccount->savingproduct->Interest_Rate) / 100,
            );
            array_push($savings, $arr);


        }
        $organization = Organization::find(1);
        $producttype = $savingproduct->name;
        $interestrate = $savingproduct->Interest_Rate;

        $title = ucwords(strtolower($savingproduct->name)) . ' Report';
        if ($data['format'] == 'pdf') {
            $pdf = app('dompdf.wrapper')->loadView('pdf.savingreports.savingproducts', compact('savings', 'interestrate', 'organization', 'period', 'title'))->setPaper('a4');

            return $pdf->stream('Saving Product Deposit.pdf');
        } else {
            return Excel::create($title, function ($excel) use ($savings, $title, $period) {

                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");


                $excel->sheet('Savings Deposits Report', function ($sheet) use ($savings, $title, $period) {

                    $sheet->setAllBorders('thin');

                    $sheet->setWidth(array(
                        'A' => 15,
                        'B' => 50,
                        'C' => 15,
                        'D' => 50,
                        'E' => 15
                    ));

                    $sheet->mergeCells('A1:E1');

                    $sheet->row(1, array($title . ' ' . $period));

                    $sheet->cells('A1:E1', function ($cells) {
                        $cells->setAlignment('center');
                        // $cells->setBackground('#777777');
                        $cells->setFont(array(
                            'family' => 'Calibri',
                            'bold' => true
                        ));
                    });

                    $sheet->mergeCells('A2:E2');


                    $sheet->row(3, array(
                        "Member", "Member Name", "Saving Product", "Account Number", "Account Balance"
                    ));

                    $sheet->cells('A3:E3', function ($cells) {
                        $cells->setFont(array(
                            'bold' => true
                        ));
                    });

                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    $row = 4;
                    $total = 0;

                    foreach ($savings as $saving) {
                        $sheet->cell('A' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['member_no']);
                        });
                        $sheet->cell('B' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['member_name']);
                        });
                        $sheet->cell('C' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['product_name']);
                        });
                        $sheet->cell('D' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['account_no']);
                        });
                        $sheet->cell('E' . $row, function ($cell) use ($saving) {
                            $cell->setValue($saving['balance']);
                        });

                        $total += $saving['balance'];
                        $row++;
                    }

                    $sheet->mergeCells('A' . $row . ':D' . $row);
                    $sheet->cells('A' . $row . ':D' . $row, function ($cells) {
                        $cells->setFont(array('bold' => true));
                    });

                    $sheet->row($row, array('Total:'));
                    $sheet->cell('E' . $row, function ($cell) use ($total) {
                        $cell->setValue(number_format($total, 2));
                        $cell->setFont(array('bold' => true));
                    });

                });

            })->export('xlsx');
        }

    }

    public function monthlyrepayments(Request $request)
    {

        $date = $request->get('date');
        $loanid = $request->get('member');
        if ($date != null && $loanid != null) {
            $scrapdate = Loanrepayment::where('loanaccount_id', '=', $loanid)
                ->get();


            $organization = Organization::find(1);
            $pdf = app('dompdf.wrapper')->loadView('pdf.monthlyrepayments', compact('date', 'scrapdate', 'organization'))->setPaper('a4', 'portrait');
            return $pdf->stream('Monthly Repayment Report.pdf');
        } else {
            return Redirect::back()
                ->withAlarm('Please select the repayment duration and the respective member');
        }
    }

    public function creditappraisal($id, $loanid)
    {
        $member = Member::where('id', '=', $id)->get()->first();
        $loans = Loanaccount::where('member_id', '=', $id)
            ->where('is_disbursed', '=', 1)
            ->get();
        $currentloan = Loanaccount::where('id', '=', $loanid)
            ->get()->first();
        $savingaccount = DB::table('savingaccounts')
            ->where('member_id', '=', $id)->pluck('account_number');
        $savings = DB::table('savingtransactions')
            ->join('savingaccounts', 'savingtransactions.savingaccount_id', '=', 'savingaccounts.id')
            ->where('savingaccounts.member_id', '=', $id)
            ->where('savingtransactions.type', '=', 'credit')
            ->sum('savingtransactions.amount');
        $shareaccount = DB::table('shareaccounts')
            ->where('member_id', '=', $id)->pluck('account_number');
        $shares = DB::table('sharetransactions')
            ->join('shareaccounts', 'sharetransactions.shareaccount_id', '=', 'shareaccounts.id')
            ->where('shareaccounts.member_id', '=', $id)
            ->where('sharetransactions.type', '=', 'credit')
            ->sum('sharetransactions.amount');
        $pdf = app('dompdf.wrapper')->loadView('pdf.loanreports.creditappraisal', compact('member', 'loans', 'savings', 'savingaccount', 'shares', 'shareaccount', 'currentloan'))->setPaper('a4');
        return $pdf->stream('Member Credit Appraisal Report.pdf');

    }


    public function financials(Request $request)
    {

        $data = $request->all();
        $report = $data['report_type'];
        $date = $data['date'];

        $accounts = Account::all();

        $organization = Organization::find(1);

        $from = $data['from'];
        $to = $data['to'];
        $period = $data['period'];
        $incomeParticular = $data['income-particulars'];
        $expenseParticular = $data['expense-particulars'];
        $arr = array();

        foreach ($accounts as $account) {
            //$date2=date('2019'.'-01'.'-01');
            //$openingbalance = Tbopeningbalance::where('account_id', '=', $account->id)->where('opening_bal_for','=',$date2)->pluck('account_balance');
            // code...
            if (!key_exists($account->category, $arr)) {
                $arr[$account->category] = [];
            }


            if ($period != 'custom') {
                $bal = array(
                    'name' => $account->name,
                    //'balance'=> Tbopeningbalance::getTrialBalanceAsAt($account,$openingbalance,$date));
                    'balance' => Account::getAccountBalanceAtDate($account, $date));

                array_push($arr[$account->category], $bal);
            } else {
                $bal = array(
                    'name' => $account->name,
                    // 'balance'=>Tbopeningbalance::getTrialBalance($account,$openingbalance,$from,$to));

                    'balance' => Account::getAccountBalanceBetween($account, $from, $to));
                array_push($arr[$account->category], $bal);
            }
        }

        if ($report == 'balancesheet') {
            $accounts = $arr;

            if ($data['format'] == 'excel') {
                return Excel::create('balancesheet', function ($excel) use ($accounts, $period, $date, $to, $from) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                    $excel->sheet('BalanceSheet', function ($sheet) use ($accounts, $period, $date, $to, $from) {

                        $sheet->setAllBorders('thin');

                        $sheet->setWidth(array(
                            'A' => 60,
                            'B' => 15,
                        ));
                        $sheet->mergeCells('A1:B1');
                        if ($period = 'As at date') {
                            $sheet->row(1, array("BALANCESHEET REPORT FOR " . $date));
                        } else {
                            $sheet->row(1, array("BALANCESHEET REPORT FROM " . $from . " TO " . $to));
                        }
                        $sheet->cells('A1:B1', function ($cells) {
                            $cells->setAlignment('center');
                            // $cells->setBackground('#777777');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });
                        $sheet->row(2, array(
                            "ACCOUNT DESCRIPTION", "AMOUNT",
                        ));
                        $sheet->cells('A2:B2', function ($cells) {
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });

                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }

                        $sheet->mergeCells('A3:B3');


                        $row = 4;
                        $total_assets = 0;
                        $total_liabilities = 0;
                        $total_equity = 0;
                        $total_income = 0;
                        $total_expense = 0;
                        $totals = array();
                        foreach ($accounts as $key => $value) {

                            $sheet->mergeCells('A' . $row . ':B' . $row);
                            $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                                $cells->setFont(array(
                                    'bold' => true
                                ));
                            });
                            $sheet->row($row, array($key));
                            $row++;
                            $totals[$key] = 0;
                            foreach ($value as $account) {
                                $sheet->cell('A' . $row, function ($cell) use ($account, $date, $period) {
                                    $cell->setValue($account['name']);
                                });

                                $sheet->cell('B' . $row, function ($cell) use ($account, $date, $period) {
                                    $cell->setValue(number_format($account['balance'], 2));
                                });


                                $totals[$key] += $account['balance'];

                                $row++;
                            }


                            $sheet->cell('A' . $row, function ($cell) use ($key) {
                                $cell->setFont(array(
                                    'bold' => true
                                ));
                                $cell->setValue("TOTALS " . $key);
                            });

                            $sheet->cell('B' . $row, function ($cell) use ($totals, $key) {
                                $cell->setValue(number_format($totals[$key], 2));
                                $cell->setFont(array(
                                    'bold' => true
                                ));
                            });
                            $row++;
                        }

                        $row += 2;
                        $sheet->mergeCells('A' . $row . ':B' . $row);

                        $sheet->row($row, array("Printed on: " . date('D M j, Y') . " by " . Auth::user()->username));

                        $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                            $cells->setAlignment('center');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });


                    });
                })->export('xlsx');
            } else {
                $pdf = app('dompdf.wrapper')->loadView('pdf.financials.balancesheet', compact('accounts', 'to', 'from', 'date', 'organization'))->setPaper('a4');

                return $pdf->stream('Balance Sheet.pdf');
            }
        }


        if ($report == 'income') {

            //return $incomedata;
            if ($data['format'] == 'excel') {
                $incomedata = array('INCOME' => array(), 'EXPENSE' => array());

                foreach ($accounts as $account) {
                    //$date2=date('2019'.'-01'.'-01');
                    //$openingbalance = Tbopeningbalance::where('account_id', '=', $account->id)->where('opening_bal_for','=',$date2)->pluck('account_balance');
                    if ($account->category == 'INCOME') {
                        $data1["name"] = $account->name;
                        //changes made for the the purpose of including openbalance figures in the trial balance...changes reversed again
                        //$data1['balance'] = Tbopeningbalance::getTrialBalance($account,$openingbalance,$from,$to);
                        $data1['balance'] = Account::getAccountBalanceAtDate($account, $date);
                        array_push($incomedata['INCOME'], $data1);
                    } elseif ($account->category == 'EXPENSE') {
                        $data1["name"] = $account->name;
                        //$data1['balance'] = Tbopeningbalance::getTrialBalance($account,$openingbalance,$from,$to);
                        $data1['balance'] = Account::getAccountBalanceAtDate($account, $date);
                        array_push($incomedata['EXPENSE'], $data1);
                    }
                }

                return Excel::create('INCOME STATEMENT', function ($excel) use ($incomedata, $period, $date, $to, $from) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                    $excel->sheet('INCOME STATEMENT', function ($sheet) use ($incomedata, $period, $date, $to, $from) {

                        $sheet->setAllBorders('thin');

                        $sheet->setWidth(array(
                            'A' => 60,
                            'B' => 15,
                        ));
                        $sheet->mergeCells('A1:B1');
                        if ($period = 'As at date') {
                            $sheet->row(1, array("INCOME STATEMENT REPORT FOR " . $date));
                        } else {
                            $sheet->row(1, array("INCOME STATEMENT REPORT FROM " . $from . " TO " . $to));
                        }
                        $sheet->cells('A1:B1', function ($cells) {
                            $cells->setAlignment('center');
                            // $cells->setBackground('#777777');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });
                        $sheet->row(2, array(
                            "ACCOUNT DESCRIPTION", "AMOUNT",
                        ));
                        $sheet->cells('A2:B2', function ($cells) {
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });

                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }

                        $sheet->mergeCells('A3:B3');


                        $row = 4;
                        $total_assets = 0;
                        $total_liabilities = 0;
                        $total_equity = 0;
                        $total_income = 0;
                        $total_expense = 0;
                        $totals = array();
                        foreach ($incomedata as $key => $value) {

                            $sheet->mergeCells('A' . $row . ':B' . $row);
                            $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                                $cells->setFont(array(
                                    'bold' => true
                                ));
                            });
                            $sheet->row($row, array($key));
                            $row++;
                            $totals[$key] = 0;
                            foreach ($value as $account) {
                                $sheet->cell('A' . $row, function ($cell) use ($account, $date, $period) {
                                    $cell->setValue($account['name']);
                                });

                                $sheet->cell('B' . $row, function ($cell) use ($account, $date, $period) {
                                    $cell->setValue(number_format($account['balance'], 2));
                                });


                                $totals[$key] += $account['balance'];

                                $row++;
                            }


                            $sheet->cell('A' . $row, function ($cell) use ($key) {
                                $cell->setFont(array(
                                    'bold' => true
                                ));
                                $cell->setValue("TOTALS " . $key);
                            });

                            $sheet->cell('B' . $row, function ($cell) use ($totals, $key) {
                                $cell->setValue(number_format($totals[$key], 2));
                                $cell->setFont(array(
                                    'bold' => true
                                ));
                            });
                            $row += 2;
                        }


                        $sheet->cell('A' . $row, function ($cell) {
                            $cell->setFont(array(
                                'bold' => true
                            ));
                            $cell->setValue("TOTALS INCOME");
                        });

                        $sheet->cell('B' . $row, function ($cell) use ($totals) {
                            $cell->setValue(number_format($totals["INCOME"] - $totals['EXPENSE'], 2));
                            $cell->setFont(array(
                                'bold' => true
                            ));
                        });
                        $row++;

                        $row += 2;
                        $sheet->mergeCells('A' . $row . ':B' . $row);

                        $sheet->row($row, array("Printed on: " . date('D M j, Y') . " by " . Auth::user()->username));

                        $sheet->cells('A' . $row . ':B' . $row, function ($cells) {
                            $cells->setAlignment('center');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });


                    });
                })->export('xlsx');
            } else {
                $pdf = app('dompdf.wrapper')->loadView('pdf.financials.incomestatement', compact('accounts', 'date', 'from', 'to', 'organization'))->setPaper('a4', 'portrait');

                return $pdf->stream('Income Statement.pdf');
            }
        }


        if ($report == 'trialbalance') {


            if ($data['format'] == 'excel') {
                return Excel::create('trialbalance', function ($excel) use ($accounts, $period, $date, $to, $from) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/Cell/DataValidation.php");
                    $excel->sheet('Trial Balance', function ($sheet) use ($accounts, $period, $date, $to, $from) {

                        $sheet->setAllBorders('thin');

                        $sheet->setWidth(array(
                            'A' => 60,
                            'B' => 15,
                            'C' => 15,
                        ));
                        $sheet->mergeCells('A1:C1');
                        if ($period = 'As at date') {
                            $sheet->row(1, array("TRIALSHEET REPORT FOR " . $date));
                        } else {
                            $sheet->row(1, array("TRIALSHEET REPORT FROM " . $from . " TO " . $to));
                        }
                        $sheet->cells('A1:C1', function ($cells) {
                            $cells->setAlignment('center');
                            // $cells->setBackground('#777777');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });
                        $sheet->row(2, array(
                            "ACCOUNT DESCRIPTION", "CREDIT", "DEBIT"
                        ));
                        $sheet->cells('A2:C2', function ($cells) {
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });

                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }

                        $sheet->mergeCells('A3:C3');


                        $row = 4;
                        $total_credits = 0;
                        $total_debits = 0;
                        foreach ($accounts as $account) {
                            if (Account::getAccountBalanceAtDate($account, $date) != 0) {
                                $sheet->cell('A' . $row, function ($cell) use ($account, $date, $period) {
                                    $cell->setValue($account['name']);
                                });
                                if ($account->category == 'ASSET' || $account->category == 'EXPENSE') {
                                    $sheet->cell('B' . $row, function ($cell) use ($account, $date, $period) {
                                        $cell->setValue(number_format(Account::getAccountBalanceAtDate($account, $date), 0));
                                    });

                                    $sheet->cell('C' . $row, function ($cell) use ($account, $date, $period) {
                                        $cell->setValue(number_format(0, 0));
                                    });

                                    $total_credits += Account::getAccountBalanceAtDate($account, $date);

                                } else {
                                    $sheet->cell('B' . $row, function ($cell) use ($account, $date, $period) {
                                        $cell->setValue(number_format(0, 0));
                                    });

                                    $sheet->cell('C' . $row, function ($cell) use ($account, $date, $period) {
                                        $cell->setValue(number_format(Account::getAccountBalanceAtDate($account, $date), 0));
                                    });

                                    $total_debits += Account::getAccountBalanceAtDate($account, $date);

                                }


                                $row++;
                            }
                        }


                        $sheet->cell('A' . $row, function ($cell) {
                            $cell->setFont(array(
                                'bold' => true
                            ));
                            $cell->setValue("TOTALS ");
                        });

                        $sheet->cell('B' . $row, function ($cell) use ($total_credits) {
                            $cell->setValue(number_format($total_credits, 2));
                            $cell->setFont(array(
                                'bold' => true
                            ));
                        });
                        $sheet->cell('C' . $row, function ($cell) use ($total_debits) {
                            $cell->setValue(number_format($total_debits, 2));
                            $cell->setFont(array(
                                'bold' => true
                            ));
                        });

                        $row += 2;
                        $sheet->mergeCells('A' . $row . ':C' . $row);

                        $sheet->row($row, array("Printed on: " . date('D M j, Y') . " by " . Auth::user()->username));

                        $sheet->cells('A' . $row . ':C' . $row, function ($cells) {
                            $cells->setAlignment('center');
                            $cells->setFont(array(
                                'bold' => true
                            ));
                        });


                    });
                })->export('xlsx');
            } else {
                $pdf = app('dompdf.wrapper')->loadView('pdf.financials.trialbalance', compact('accounts', 'from', 'to', 'period', 'date', 'organization'))->setPaper('a4', 'portrait');

                return $pdf->stream('Trial Balance.pdf');
            }

        }

        if ($report == 'cashbook') {
            return view('pdf.financials.cashbookperiod');
        }

        if ($report == 'budget') {
            $set_year = date('Y', strtotime($date));
            $previous_year = $set_year - 1;

            $projections = array(
                'Interest' => DB::table('proposal_entries')->select('proposal_entries.year', 'proposal_entries.first_quarter', 'proposal_entries.second_quarter', 'proposal_entries.third_quarter', 'proposal_entries.fourth_quarter', 'proposal_categories.type', 'proposal_categories.name')
                    ->join('proposal_categories', 'proposal_entries.proposal_category_id', '=', 'proposal_categories.id')
                    ->where('proposal_entries.year', '=', $set_year)
                    ->where('proposal_categories.type', '=', 'INTEREST')
                    ->get(),
                'Income' => DB::table('proposal_entries')->select('proposal_entries.year', 'proposal_entries.first_quarter', 'proposal_entries.second_quarter', 'proposal_entries.third_quarter', 'proposal_entries.fourth_quarter', 'proposal_categories.type', 'proposal_categories.name')
                    ->join('proposal_categories', 'proposal_entries.proposal_category_id', '=', 'proposal_categories.id')
                    ->where('proposal_entries.year', '=', $set_year)
                    ->where('proposal_categories.type', '=', 'OTHER INCOME')
                    ->get(),
                'Expenditure' => DB::table('proposal_entries')->select('proposal_entries.year', 'proposal_entries.first_quarter', 'proposal_entries.second_quarter', 'proposal_entries.third_quarter', 'proposal_entries.fourth_quarter', 'proposal_categories.type', 'proposal_categories.name')
                    ->join('proposal_categories', 'proposal_entries.proposal_category_id', '=', 'proposal_categories.id')
                    ->where('proposal_entries.year', '=', $set_year)
                    ->where('proposal_categories.type', '=', 'EXPENDITURE')
                    ->get()
            );

            $pdf = app('dompdf.wrapper')->loadView('pdf.budget_report', compact('set_year', 'previous_year', 'projections'))
                ->setPaper('a4', 'landscape');
            return $pdf->stream($set_year . '_budget_report.pdf');
        }

        if ($report == 'income_reports') {
            $incomeAccounts = Account::select('id')->where('category', 'INCOME')->get()->toArray();
            $particulars = Particular::select('id')->whereIn('creditaccount_id', $incomeAccounts)->get();

            if ($incomeParticular != '0') {
                $particular = Particular::findOrFail($incomeParticular);
                foreach ($incomeAccounts as $key => $incomeAccount) {
                    if ($incomeAccount['id'] != $particular->creditaccount_id) {
                        unset($incomeAccounts[$key]);
                    }
                }

                if ($period == 'As at date') {
                    $incomeSums = Journal::whereIn('account_id', $incomeAccounts)
                        ->where('particulars_id', $incomeParticular)
                        ->where('date', $date)
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                } else {
                    $incomeSums = Journal::whereIn('account_id', $incomeAccounts)
                        ->where('particulars_id', $incomeParticular)
                        ->whereBetween('date', array($from, $to))
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                }
            } else {

                if ($period == 'As at date') {
                    $incomeSums = Journal::whereIn('account_id', $incomeAccounts)
                        ->whereNotNull('particulars_id')
                        ->where('date', $date)
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                } else {
                    $incomeSums = Journal::whereIn('account_id', $incomeAccounts)
                        ->whereNotNull('particulars_id')
                        ->where('void', false)
                        ->whereBetween('date', array($from, $to))
                        ->orderBy('date')
                        ->get();
                }
            }

            $incomes = array();

            foreach ($incomeSums as $income) {
                $particular = $income->particular->name;
                if (key_exists($particular, $incomes)) {
                    $incomes[$particular]['amount'] += $income->amount;
                } else {
                    $incomes[$particular]['amount'] = $income->amount;
                    $incomes[$particular]['income'] = $income;
                }
            }

            $pdf = app('dompdf.wrapper')->loadView('pdf.financials.income_reports', compact('incomeAccounts', 'date', 'organization', 'from', 'to', 'period', 'particulars', 'incomeSums', 'incomes'))->setPaper('a4');
            return $pdf->stream('Income Report.pdf');
        }

        if ($report == 'expenses_reports') {
            $expenseAccounts = Account::select('id')->where('category', 'EXPENSE')->get()->toArray();
            $particulars = Particular::whereIn('debitaccount_id', $expenseAccounts)->get();

            if ($expenseParticular != '0') {
                $particular = Particular::findOrFail($expenseParticular);
                foreach ($expenseAccounts as $key => $expenseAccount) {
                    if ($expenseAccount['id'] != $particular->debitaccount_id) {
                        unset($expenseAccounts[$key]);
                    }
                }

                if ($period == 'As at date') {
                    $expenses = Journal::whereIn('account_id', $expenseAccounts)
                        ->where('particulars_id', $expenseParticular)
                        ->where('date', $date)
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                } else {
                    $expenses = Journal::whereIn('account_id', $expenseAccounts)
                        ->where('particulars_id', $expenseParticular)
                        ->whereBetween('date', array($from, $to))
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                }
            } else {

                if ($period == 'As at date') {
                    $expenses = Journal::whereIn('account_id', $expenseAccounts)
                        ->whereNotNull('particulars_id')
                        ->where('date', $date)
                        ->where('void', false)
                        ->orderBy('date')
                        ->get();
                } else {
                    $expenses = Journal::whereIn('account_id', $expenseAccounts)
                        ->whereNotNull('particulars_id')
                        ->whereBetween('date', array($from, $to))
                        ->orderBy('date')
                        ->where('void', false)
                        ->get();
                }
            }

            $expe = array();

            foreach ($expenses as $expense) {
                $particular = $expense->particular->name;
                if (key_exists($particular, $expe)) {
                    $expe[$particular]['amount'] += $expense->amount;
                } else {
                    $expe[$particular]['amount'] = $expense->amount;
                    $expe[$particular]['expense'] = $expense;
                }
            }


            $pdf = app('dompdf.wrapper')->loadView('pdf.financials.expenses_reports', compact('expenseAccounts', 'date', 'organization', 'from', 'to', 'period', 'particulars', 'expenses', 'expe'))
                ->setPaper('a4');
            return $pdf->stream('Expenses Report.pdf');

        }


    }

    /**
     * GENERATE BANK RECONCILIATION REPORT
     */
    public function displayRecOptions()
    {
        $bankAccounts = DB::table('x_bank_accounts')
            ->get();

        $bookAccounts = DB::table('x_accounts')
            ->where('category', 'ASSET')
            ->get();

        return view('banking.recOptions', compact('bankAccounts', 'bookAccounts'));
    }

    public function showRecReport(Request $request)
    {
        $bankAcID = $request->get('bank_account');
        $bookAcID = $request->get('book_account');
        $recMonth = $request->get('rec_month');

        //get statement id
        $bnkStmtID = DB::table('x_bank_statements')
            ->where('stmt_month', $recMonth)
            ->pluck('id');
        dd($recMonth);

        $bnkStmtBal = DB::table('x_bank_statements')
            ->where('bank_account_id', $bankAcID)
            ->where('stmt_month', $recMonth)
            ->select('bal_bd')
            ->first();

        $acTransaction = DB::table('x_account_transactions')
            ->where('status', '=', 'RECONCILED')
            ->where('bank_statement_id', $bnkStmtID)
            ->whereMonth('transaction_date', '=', substr($recMonth, 0, 2))
            ->whereYear('transaction_date', '=', substr($recMonth, 3, 6))
            ->select('id', 'account_credited', 'account_debited', 'transaction_amount')
            ->get();

        $bkTotal = 0;
        foreach ($acTransaction as $acnt) {
            if ($acnt->account_debited == $bookAcID) {
                $bkTotal += $acnt->transaction_amount;
            } else if ($acnt->account_credited == $bookAcID) {
                $bkTotal -= $acnt->transaction_amount;
            }
        }

        $additions = DB::table('x_account_transactions')
            ->where('status', '=', 'RECONCILED')
//            ->where('bank_statement_id', $bnkStmtID)
            ->whereMonth('transaction_date', '<>', substr($recMonth, 0, 2))
            ->whereYear('transaction_date', '=', substr($recMonth, 3, 6))
            ->select('id', 'description', 'account_credited', 'account_debited', 'transaction_amount')
            ->get();

        $add = [];
        $less = [];
        foreach ($additions as $addition) {
            if ($addition->account_debited == $bookAcID) {
                array_push($add, $addition);
            } else if ($addition->account_credited == $bookAcID) {
                array_push($less, $addition);
            }
        }

        $organization = Organization::find(1);

        $pdf = app('dompdf.wrapper')->loadView('banking.bankReconciliationReport', compact('recMonth', 'organization', 'bnkStmtBal', 'bkTotal', 'add', 'less'))->setPaper('a4', 'portrait');
        return $pdf->stream('Reconciliation Reports');
        /*if(count($bnkStmtBal) == 0 || $bkTotal == 0 || count($additions) == 0 ){
					return "Error";
					//return view('erpreports.bankReconciliationReport')->with('error','Cannot generate report for this Reconciliation! Please check paremeters!');
			} else{
					return "Success";*/
        return view('banking.bankReconciliationReport', compact('recMonth', 'organization', 'bnkStmtBal', 'bkTotal', 'add', 'less'));
        //}
    }

    public function selecttransactionPeriod()
    {

        $transaction = AccountTransaction::all();
        return view('reports.selecttransactionperiod', compact('transaction'));
    }

    public function transaction(Request $request)
    {
        $from = $request->get('from');
        $to = $request->get('to');
        $data = $request->all();
        $accounts = Account::where('name', 'like', '%' . 'Bank Account' . '%')->pluck('id');
        //get deposits
        $type = AccountTransaction::where('account_debited', $accounts)->pluck('description');
        $transaction = AccountTransaction::whereBetween('transaction_date', array($request->get('from'), $request->get('to')))->where('description', $type)->get();

        //get withdrawals
        $type1 = AccountTransaction::where('account_credited', $accounts)->pluck('description');
        $transaction1 = AccountTransaction::whereBetween('transaction_date', array($request->get('from'), $request->get('to')))->where('description', $type1)->get();


        $organization = Organization::find(1);
        //  $transaction=AccountTransaction::whereBetween('transaction_date', array($request->get('from'),$request->get('to')))->where('description',$type)->get();
        $pdf = app('dompdf.wrapper')->loadView('reports.transactionReport', compact('transaction', 'transaction1', 'organization', 'from', 'type', 'to'))->setPaper('a4');
        return $pdf->stream('Transactions Reports');

    }

    public function period_advsummary()
    {
        $branches = Branch::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $depts = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        return view('pdf.summaryAdvanceSelect', compact('branches', 'depts'));
    }

    public function payAdvSummary(Request $request)
    {
        $period = $request->get('period');
        $date = $request->get('day');
        $month = $request->get('month');
        $year = $request->get('year');

        if ($period == 'day') {
            $period = $date;
        }
        if ($period == 'month') {
            $period = $month;
        }
        if ($period == 'year') {
            $period = $year;
        }

        if ($request->get('format') == "excel") {
            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                $total = DB::table('transact_advances')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Summary ' . $month, function ($excel) use ($data, $total, $organization, $currency) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet;
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Summary', function (Request $request, $sheet) use ($data, $total, $organization, $currency, $objPHPExcel) {

                        $sheet->row(1, array(
                            'BRANCH: ', 'ALL'
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'DEPARTMENT: ', 'ALL'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');

                        $sheet->row(6, array(
                            'ADVANCE SALARY SUMMARY'
                        ));

                        $sheet->row(6, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->row(8, array(
                            'PAYROLL NO.', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });


                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else if ($request->get('department') == 'All') {

                $sels = DB::table('branches')->find($request->get('branch'));

                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->sum('amount');

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Summary ' . $month, function ($excel) use ($data, $total, $organization, $currency, $sels) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Summary', function (Request $request, $sheet) use ($data, $total, $organization, $currency, $sels, $objPHPExcel) {

                        $sheet->row(1, array(
                            'BRANCH: ', $sels->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'DEPARTMENT: ', 'ALL'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');

                        $sheet->row(6, array(
                            'ADVANCE SALARY SUMMARY'
                        ));

                        $sheet->row(6, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->row(8, array(
                            'PAYROLL NO.', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });


                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else if ($request->get('branch') == 'All') {
                $sels = DB::table('departments')->find($request->get('department'));

                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->sum('amount');

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Summary ' . $month, function ($excel) use ($data, $total, $organization, $currency, $sels) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Summary', function (Request $request, $sheet) use ($data, $total, $organization, $currency, $sels, $objPHPExcel) {

                        $sheet->row(1, array(
                            'BRANCH: ', 'ALL'
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'DEPARTMENT: ', $sels->department_name
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');

                        $sheet->row(6, array(
                            'ADVANCE SALARY SUMMARY'
                        ));

                        $sheet->row(6, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->row(8, array(
                            'PAYROLL NO.', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });


                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {
                $selBr = DB::table('branches')->find($request->get('branch'));
                $selDt = DB::table('departments')->find($request->get('department'));

                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('department_id', '=', $request->get('department'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->sum('amount');


                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                    ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('department_id', '=', $request->get('department'))
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Summary ' . $month, function ($excel) use ($data, $total, $organization, $currency, $selBr, $selDt) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Summary', function (Request $requst, $sheet) use ($data, $total, $organization, $currency, $selBr, $selDt, $objPHPExcel) {

                        $sheet->row(1, array(
                            'BRANCH: ', $selBr->name
                        ));
                        // manipulate the cell
                        $sheet->cell('A1', function ($cell) {
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'DEPARTMENT: ', $selDt->department_name
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell

                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');

                        $sheet->row(6, array(
                            'ADVANCE SALARY SUMMARY'
                        ));

                        $sheet->row(6, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->row(8, array(
                            'PAYROLL NO.', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });


                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }

                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            // $period = $request->get("period");
            $selBranch = $request->get("branch");
            $selDept = $request->get("department");


            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                $total_amount = DB::table('x_transact_advances')
                    ->where('organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $sums = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);
                $part = ($request->get('period'));
                //$part = implode("-", $request->get('period'));


                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryAdvanceReport', compact('sums', 'selBranch', 'selDept', 'total_amount', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_summary_' . $month . '.pdf');

            } else if ($request->get('department') == 'All') {
                $sels = DB::table('x_branches')->find($request->get('branch'));

                $total_amount = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $sums = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('x_branches', 'x_employee.branch_id', '=', 'x_branches.id')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);
                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryAdvanceReport', compact('sums', 'selBranch', 'selDept', 'sels', 'total_amount', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_summary_' . $month . '.pdf');

            } else if ($request->get('branch') == 'All') {
                $sels = DB::table('departments')->find($request->get('department'));

                $total_amount = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $sums = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryAdvanceReport', compact('sums', 'selBranch', 'selDept', 'sels', 'total_amount', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_summary_' . $month . '.pdf');


            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {
                $selBr = DB::table('branches')->find($request->get('branch'));
                $selDt = DB::table('departments')->find($request->get('department'));

                $total_amount = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('department_id', '=', $request->get('department'))
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');


                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $sums = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                    ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('department_id', '=', $request->get('department'))
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryAdvanceReport', compact('sums', 'selBranch', 'selDept', 'selBr', 'selDt', 'total_amount', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_summary_' . $month . '.pdf');

            }

        }
    }

    public function period_advrem()
    {
        $branches = Branch::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $depts = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        return view('pdf.remittanceAdvanceSelect', compact('branches', 'depts'));
    }

    public function payeAdvRems(Request $request)
    {
        $data = $request->all();
        $period = $request->get('period');
        $date = $request->get('day');
        $year = $request->get('year');
        $month = $request->get('month');
        $from = $request->get('from');
        $to = $request->get('to');

        if ($period == 'day') {
            // $title = 'Deposit report as at'.date('Y-m-d', strtotime($date));
            $period = $date;

        }
        if ($period == 'month') {
            $from = date('Y-m-01', strtotime('01-' . $month));
            $to = date('Y-m-t', strtotime($from));
            //   $title = 'Deposit report for the period'.date('Y-m-d',strtotime($to));
            $period = $month;
        }
        if ($period == 'year') {
            $from = $year . '-01-01';
            $to = $year . '-12-31';
            //   $title = 'Deposit report for the year'.$year;
            $period = $year;

        }
        if ($period == 'custom') {
            $from = date('Y-m-d', strtotime($from));
            $to = date('Y-m-d', strtotime($to));
            //  $title = 'Deposit report for the period'.date('d-m-Y',strtotime($from, $to));

        }
        if ($request->get('format') == "excel") {
            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                $total = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $period)
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('mode_of_payment', '=', 'Bank')
                    ->sum('amount');

                $data = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->where('mode_of_payment', '=', 'Bank')
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();


                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Remittances ' . $month, function ($excel) use ($data, $branch, $total, $organization, $currency) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Remittances', function ($sheet) use ($data, $total, $branch, $organization, $currency, $objPHPExcel) {
                        $orgbankname = '';
                        $orgbankbranchname = '';

                        if ($organization->bank_id == 0) {
                            $orgbankname = '';
                        } else {
                            $orgbankname = Bank::getName($organization->bank_id);
                        }

                        if ($organization->bank_branch_id == 0) {
                            $orgbankbranchname = '';
                        } else {
                            $orgbankbranchname = $branch->bank_branch_name;
                        }

                        $sheet->row(1, array(
                            'BANK NAME: ', $orgbankname
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'BANK BRANCH: ', $orgbankbranchname
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'BANK ACCOUNT:', $organization->bank_account_number
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'BANK ACCOUNT:', $organization->swift_code
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A5', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(6, array(
                            'PERIOD:', $period
                        ));

                        $sheet->cell('A6', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A8:H8');

                        $sheet->row(8, array(
                            'SALARY ADVANCE TRANSFER LETTER'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->mergeCells('A10:H10');

                        $sheet->row(10, array(
                            'Please arrange to transfer funds to the below listed employees` respective bank accounts
'
                        ));

                        $sheet->row(12, array(
                            'PAYROLL NO.', 'x_employee', 'ID NO.', 'BANK', 'BANK BRANCH', 'BANK ACCOUNT', 'SWIFT CODE', 'AMOUNT'
                        ));

                        $sheet->row(12, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 13;


                        for ($i = 0; $i < count($data); $i++) {
                            $bankname = '';
                            $bankbranchname = '';
                            $name = '';

                            if ($data[$i]->bank_id == 0) {
                                $bankname = '';
                            } else {
                                $bankname = $data[$i]->bank_name;
                            }

                            if ($data[$i]->bank_branch_id == 0) {
                                $bankbranchname = '';
                            } else {
                                $bankbranchname = $data[$i]->bank_branch_name;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->identity_number, $bankname, $bankbranchname, $data[$i]->bank_account_number, $data[$i]->swift_code, $data[$i]->amount
                            ));

                            $sheet->cell('H' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('H' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->mergeCells('A' . ($row + 2) . ':H' . ($row + 2));

                        $sheet->row($row + 2, array(
                            'Please debit our account with your bank charges and confirm once the above transfer has been made.'
                        ));

                    });

                })->download('xls');
            } else if ($request->get('department') == 'All') {

                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();


                $part = implode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Remittances ' . $month, function ($excel) use ($data, $total, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Remittances', function ($sheet) use ($data, $total, $organization, $currency, $branch, $bank, $objPHPExcel) {
                        $orgbankname = '';
                        $orgbankbranchname = '';

                        if ($organization->bank_id == 0) {
                            $orgbankname = '';
                        } else {
                            $orgbankname = Bank::getName($organization->bank_id);
                        }

                        if ($organization->bank_branch_id == 0) {
                            $orgbankbranchname = '';
                        } else {
                            $orgbankbranchname = $branch->bank_branch_name;
                        }

                        $sheet->row(1, array(
                            'BANK NAME: ', $orgbankname
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'BANK BRANCH: ', $orgbankbranchname
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'BANK ACCOUNT:', $organization->bank_account_number
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'BANK ACCOUNT:', $organization->swift_code
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A5', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(6, array(
                            'PERIOD:', $period
                        ));

                        $sheet->cell('A6', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A8:H8');

                        $sheet->row(8, array(
                            'SALARY ADVANCE TRANSFER LETTER'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->mergeCells('A10:H10');

                        $sheet->row(10, array(
                            'Please arrange to transfer funds to the below listed employees` respective bank accounts
'
                        ));

                        $sheet->row(12, array(
                            'PAYROLL NO.', 'x_employee', 'ID NO.', 'BANK', 'BANK BRANCH', 'BANK ACCOUNT', 'SWIFT CODE', 'AMOUNT'
                        ));

                        $sheet->row(12, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 13;


                        for ($i = 0; $i < count($data); $i++) {
                            $bankname = '';
                            $bankbranchname = '';
                            $name = '';

                            if ($data[$i]->bank_id == 0) {
                                $bankname = '';
                            } else {
                                $bankname = $data[$i]->bank_name;
                            }

                            if ($data[$i]->bank_branch_id == 0) {
                                $bankbranchname = '';
                            } else {
                                $bankbranchname = $data[$i]->bank_branch_name;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->identity_number, $bankname, $bankbranchname, $data[$i]->bank_account_number, $data[$i]->swift_code, $data[$i]->amount
                            ));

                            $sheet->cell('H' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('H' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->mergeCells('A' . ($row + 2) . ':H' . ($row + 2));

                        $sheet->row($row + 2, array(
                            'Please debit our account with your bank charges and confirm once the above transfer has been made.'
                        ));

                    });

                })->download('xls');
            } else if ($request->get('branch') == 'All') {
                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->sum('amount');

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Remittances ' . $month, function ($excel) use ($data, $total, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet;
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Remittances', function (Request $request, $sheet) use ($data, $total, $organization, $currency, $branch, $bank, $objPHPExcel) {
                        $orgbankname = '';
                        $orgbankbranchname = '';

                        if ($organization->bank_id == 0) {
                            $orgbankname = '';
                        } else {
                            $orgbankname = Bank::getName($organization->bank_id);
                        }

                        if ($organization->bank_branch_id == 0) {
                            $orgbankbranchname = '';
                        } else {
                            $orgbankbranchname = $branch->bank_branch_name;
                        }

                        $sheet->row(1, array(
                            'BANK NAME: ', $orgbankname
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'BANK BRANCH: ', $orgbankbranchname
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'BANK ACCOUNT:', $organization->bank_account_number
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'BANK ACCOUNT:', $organization->swift_code
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A5', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(6, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A6', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A8:H8');

                        $sheet->row(8, array(
                            'SALARY ADVANCE TRANSFER LETTER'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->mergeCells('A10:H10');

                        $sheet->row(10, array(
                            'Please arrange to transfer funds to the below listed employees` respective bank accounts
'
                        ));

                        $sheet->row(12, array(
                            'PAYROLL NO.', 'x_employee', 'ID NO.', 'BANK', 'BANK BRANCH', 'BANK ACCOUNT', 'SWIFT CODE', 'AMOUNT'
                        ));

                        $sheet->row(12, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 13;


                        for ($i = 0; $i < count($data); $i++) {
                            $bankname = '';
                            $bankbranchname = '';
                            $name = '';

                            if ($data[$i]->bank_id == 0) {
                                $bankname = '';
                            } else {
                                $bankname = $data[$i]->bank_name;
                            }

                            if ($data[$i]->bank_branch_id == 0) {
                                $bankbranchname = '';
                            } else {
                                $bankbranchname = $data[$i]->bank_branch_name;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->identity_number, $bankname, $bankbranchname, $data[$i]->bank_account_number, $data[$i]->swift_code, $data[$i]->amount
                            ));

                            $sheet->cell('H' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('H' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->mergeCells('A' . ($row + 2) . ':H' . ($row + 2));

                        $sheet->row($row + 2, array(
                            'Please debit our account with your bank charges and confirm once the above transfer has been made.'
                        ));

                    });

                })->download('xls');
            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {
                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->sum('amount');

                $data = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->get();

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();


                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Salary Advance Remittances ' . $month, function ($excel) use ($data, $total, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet;
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Salary Advance Remittances', function ($sheet) use ($data, $total, $organization, $currency, $branch, $bank, $objPHPExcel) {
                        $orgbankname = '';
                        $orgbankbranchname = '';

                        if ($organization->bank_id == 0) {
                            $orgbankname = '';
                        } else {
                            $orgbankname = Bank::getName($organization->bank_id);
                        }

                        if ($organization->bank_branch_id == 0) {
                            $orgbankbranchname = '';
                        } else {
                            $orgbankbranchname = $branch->bank_branch_name;
                        }

                        $sheet->row(1, array(
                            'BANK NAME: ', $orgbankname
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'BANK BRANCH: ', $orgbankbranchname
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(3, array(
                            'BANK ACCOUNT:', $organization->bank_account_number
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'BANK ACCOUNT:', $organization->swift_code
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            'CURRENCY:', $currency->shortname
                        ));

                        $sheet->cell('A5', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(6, array(
                            'PERIOD:', $request->get('period')
                        ));

                        $sheet->cell('A6', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A8:H8');

                        $sheet->row(8, array(
                            'SALARY ADVANCE TRANSFER LETTER'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->mergeCells('A10:H10');

                        $sheet->row(10, array(
                            'Please arrange to transfer funds to the below listed employees` respective bank accounts
'
                        ));

                        $sheet->row(12, array(
                            'PAYROLL NO.', 'x_employee', 'ID NO.', 'BANK', 'BANK BRANCH', 'BANK ACCOUNT', 'SWIFT CODE', 'AMOUNT'
                        ));

                        $sheet->row(12, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 13;


                        for ($i = 0; $i < count($data); $i++) {
                            $bankname = '';
                            $bankbranchname = '';
                            $name = '';

                            if ($data[$i]->bank_id == 0) {
                                $bankname = '';
                            } else {
                                $bankname = $data[$i]->bank_name;
                            }

                            if ($data[$i]->bank_branch_id == 0) {
                                $bankbranchname = '';
                            } else {
                                $bankbranchname = $data[$i]->bank_branch_name;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }
                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->identity_number, $bankname, $bankbranchname, $data[$i]->bank_account_number, $data[$i]->swift_code, $data[$i]->amount
                            ));

                            $sheet->cell('H' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('H' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->mergeCells('A' . ($row + 2) . ':H' . ($row + 2));

                        $sheet->row($row + 2, array(
                            'Please debit our account with your bank charges and confirm once the above transfer has been made.'
                        ));

                    });

                })->download('xls');
            }
        } else {
// $period = date('m-Y',strtotime($request->get('period')));


            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {

                $total = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $rems = DB::table('x_transact_advances')
                    ->join('x_employee', 'x_transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);
                $branch = DB::table('bank_branches')
                    ->join('x_organizations', 'bank_branches.organization_id', '=', 'x_organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('x_organizations', 'banks.organization_id', '=', 'x_organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();
//                $part = implode("-", $period);
                $part = $period;

                $m = "";
                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.advanceremittanceReport', compact('rems', 'branch', 'bank', 'total', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_Remittance_' . $month . '.pdf');
            } else if ($request->get('department') == 'All') {
                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $rems = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();


                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.advanceremittanceReport', compact('rems', 'branch', 'bank', 'total', 'emps', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_Remittance_' . $month . '.pdf');

            } else if ($request->get('branch') == 'All') {
                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('currencies')
                    ->select('shortname')
                    ->get();

                $rems = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('department_id', '=', $request->get('department'))
                    ->where('financial_month_year', '=', $period)
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('mode_of_payment', '=', 'Bank')
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.advanceremittanceReport', compact('rems', 'total', 'branch', 'bank', 'from', 'to', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_Remittance_' . $month . '.pdf');

            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {
                $total = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $period)
                    ->sum('amount');

                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $rems = DB::table('transact_advances')
                    ->join('x_employee', 'transact_advances.employee_id', '=', 'x_employee.personal_file_number')
                    ->join('banks', 'x_employee.bank_id', '=', 'banks.id')
                    ->join('bank_branches', 'x_employee.bank_branch_id', '=', 'bank_branches.id')
                    ->where('branch_id', '=', $request->get('branch'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('department_id', '=', $request->get('department'))
                    ->where('mode_of_payment', '=', 'Bank')
                    ->where('financial_month_year', '=', $period)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $part = implode("-", $period);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $pdf = app('dompdf.wrapper')->loadView('pdf.advanceremittanceReport', compact('rems', 'branch', 'bank', 'total', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Advance_Remittance_' . $month . '.pdf');

            }

        }

    }

    public function period_payslip()
    {
        $employees = DB::table('x_employee')
            ->where('x_employee.organization_id', Auth::user()->organization_id)->get();
        $branches = Branch::whereNull('organization_id')
            ->orWhere('organization_id', Auth::user()->organization_id)->get();
        $departments = Department::whereNull('organization_id')
            ->orWhere('organization_id', Auth::user()->organization_id)->get();
        #echo '<pre>'; print_r($employees); echo '</pre>'; die;
        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })
            ->where('job_group_name', 'Management')
            ->first();
        if (!empty($jgroup)) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)
                ->where('job_group_id', $jgroup->id)
                ->where('personal_file_number', Auth::user()->username)->count();
        } else {
            $type = Employee::where('organization_id', Auth::user()->organization_id)
                ->/*where('job_group_id',$jgroup->id)->*/
                where('personal_file_number', Auth::user()->username)->count();
        }
        return view('pdf.payslipSelect', compact('employees', 'branches', 'departments', 'type'));
    }

    public function payslip(Request $request)
    {
        set_time_limit(2000);
        $check = DB::table('x_transact')
            ->where('financial_month_year', '=', $request->get('period'))
            ->count();
        if ($check == 0) {
            return Redirect::back()->with('notice', 'No payslip is processed for this month!');
        }
        if ($request->get('format') == "excel") {
            if ($request->get('employeeid') == 'All') {
                return Redirect::back()->withErrors("Please select PDF format for all employees selection!");
            } else {
                $period = $request->get("period");

                $id = $request->get('employeeid');

                $employee = Employee::find($id);

                $data = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->first();

                $nontaxables = DB::table('transact_nontaxables')
                    ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('nontaxable_name')
                    ->select('nontaxable_name', DB::raw('COALESCE(sum(nontaxable_amount),0.00) as nontaxable_amount'))
                    ->get();

                $allws = DB::table('transact_allowances')
                    ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('allowance_name')
                    ->select('allowance_name', DB::raw('COALESCE(sum(allowance_amount),0.00) as allowance_amount'))
                    ->get();

                $earnings = DB::table('transact_earnings')
                    ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('earning_name')
                    ->select('earning_name', DB::raw('COALESCE(sum(earning_amount),0.00) as earning_amount'))
                    ->get();

                $deds = DB::table('transact_deductions')
                    ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('deduction_name')
                    ->select('deduction_name', DB::raw('COALESCE(sum(deduction_amount),0.00) as deduction_amount'))
                    ->get();

                $overtimes = DB::table('transact_overtimes')
                    ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('overtime_type')
                    ->select('overtime_type', DB::raw('COALESCE(sum(overtime_period*overtime_amount),0.00) as overtimes'))
                    ->get();

                $rels = DB::table('transact_reliefs')
                    ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->groupBy('relief_name')
                    ->select('relief_name', DB::raw('COALESCE(sum(relief_amount),0.00) as relief_amount'))
                    ->get();

                $pension = DB::table('transact_pensions')
                    ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.id', '=', $request->get('employeeid'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->select(DB::raw('COALESCE(sum(employee_amount),0.00) as employee_amount'))
                    ->first();

                $save = '';

                $name = '';

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                //dd($employee->middle_name);

                if ($employee->middle_name == '' || $employee->middle_name == null) {
                    $save = $employee->personal_file_number . ' - ' . $employee->first_name . ' ' . $employee->last_name;
                } else {
                    $save = $employee->personal_file_number . ' - ' . $employee->first_name . ' ' . $employee->middle_name . ' ' . $employee->last_name;
                }

                if ($employee->middle_name == '' || $employee->middle_name == null) {
                    $name = $employee->first_name . ' ' . $employee->last_name;
                } else {
                    $name = $employee->first_name . ' ' . $employee->middle_name . ' ' . $employee->last_name;
                }

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                Audit::logaudit(Carbon::now(), 'view', 'viewed payslip for ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name . ' for period ' . $request->get('period'));


                Excel::create($save . '_' . $month . ' Payslip', function ($excel) use ($data, $nontaxables, $name, $period, $employee, $allws, $earnings, $overtimes, $rels, $deds, $pension, $organization, $currency) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet;
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);
                    $excel->sheet('Payslip', function ($sheet) use ($data, $nontaxables, $name, $period, $employee, $allws, $earnings, $overtimes, $rels, $deds, $organization, $currency, $pension, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'Period: ', $period
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A4:B4');

                        $sheet->row(4, array(
                            'PERSONAL DETAILS'
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            'Payroll Number: ', $employee->personal_file_number
                        ));

                        $sheet->row(5, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('left');

                        });

                        $sheet->row(6, array(
                            'Employee Name: ', $name
                        ));

                        $sheet->row(7, array(
                            'Identity Number: ', $employee->identity_number
                        ));

                        $sheet->row(7, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('left');

                        });

                        $sheet->row(8, array(
                            'KRA Pin: ', $employee->pin
                        ));

                        $sheet->row(8, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('left');

                        });

                        $sheet->row(9, array(
                            'Nssf Number: ', $employee->social_security_number
                        ));

                        $sheet->row(9, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('left');

                        });

                        $sheet->row(10, array(
                            'Nhif Number: ', $employee->hospital_insurance_number
                        ));

                        $sheet->row(10, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('left');

                        });

                        $sheet->row(12, array(
                            'EARNINGS ', 'AMOUNT (' . $currency->shortname . ')'
                        ));

                        $sheet->row(12, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(13, array(
                            'Basic Pay: ', $data->basic_pay
                        ));

                        $sheet->cell('B13', function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $row = 14;

                        for ($i = 0; $i < count($earnings); $i++) {

                            $sheet->row($row, array(
                                $earnings[$i]->earning_name, $earnings[$i]->earning_amount
                            ));

                            $sheet->cell('B' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }

                        for ($i = 0; $i < count($overtimes); $i++) {

                            $sheet->row($row, array(
                                'Overtime Earning - ' . $overtimes[$i]->overtime_type, $overtimes[$i]->overtimes
                            ));

                            $sheet->cell('B' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }

                        $sheet->row($row, array(
                            'ALLOWANCES'
                        ));

                        $sheet->row($row, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        for ($i = 0; $i < count($allws); $i++) {

                            $sheet->row($row + 1, array(
                                $allws[$i]->allowance_name, $allws[$i]->allowance_amount
                            ));

                            $sheet->cell('B' . ($row + 1), function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }

                        $sheet->row($row + 1, array(
                            'GROSS PAY', $data->taxable_income
                        ));

                        $sheet->row($row + 1, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->cell('B' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $r = $row + 2;

                        for ($i = 0; $i < count($nontaxables); $i++) {

                            $sheet->row($r, array(
                                $nontaxables[$i]->nontaxable_name, $nontaxables[$i]->nontaxable_amount
                            ));

                            $sheet->cell('B' . $r, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $r++;

                        }

                        for ($i = 0; $i < count($rels); $i++) {

                            $sheet->row($r, array(
                                $rels[$i]->relief_name, $rels[$i]->relief_amount
                            ));

                            $sheet->cell('B' . $r, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $r++;

                        }

                        $sheet->row($r, array(
                            'DEDUCTIONS'
                        ));

                        $sheet->row($r, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row($r + 1, array(
                            'Paye:', $data->paye
                        ));

                        $sheet->cell('B' . ($r + 1), function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->row($r + 2, array(
                            'Nssf:', $data->nssf_amount
                        ));

                        $sheet->cell('B' . ($r + 2), function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->row($r + 3, array(
                            'Nhif:', $data->nhif_amount
                        ));

                        $sheet->cell('B' . ($r + 3), function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $c = $r + 4;

                        for ($i = 0; $i < count($deds); $i++) {

                            $sheet->row($c, array(
                                $deds[$i]->deduction_name, $deds[$i]->deduction_amount
                            ));

                            $sheet->cell('B' . $c, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $c++;

                        }

                        $sheet->row($c, array(
                            'PENSION CONTRIBUTION:', $pension->employee_amount
                        ));

                        $sheet->row($c + 1, array(
                            'TOTAL DEDUCTIONS:', $data->total_deductions
                        ));

                        $sheet->row($c + 1, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->cell('B' . ($c + 1), function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->row($c + 2, array(
                            'NET PAY:', $data->net
                        ));

                        $sheet->row($c + 2, function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->cell('B' . ($c + 2), function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } elseif ($request->get('format') == "pdf") {
            if ($request->get("employeeid") == 'All') {

                $period = $request->get("period");
                $select = $request->get("employeeid");
                $id = $request->get('employeeid');

                $empall = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->get();


                $currency = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->first();

                $organization = Organization::find(Auth::user()->organization_id);

                // $type = $request->type;
                // $jgroup = Jobgroup::where(function ($query) {
                //     $query->whereNull('organization_id')
                //         ->orWhere('organization_id', Auth::user()->organization_id);
                // })->where('job_group_name', $type)
                //     ->first();

                $empall = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->get();

                $empall = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->get();
                $transacts = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->first();

                    // Commented out by dominick Kyengo on 31/08/2023
                    //->where('x_employee.id', '=', $request->get('employeeid'))

                Audit::logaudit(Carbon::now(), 'view', 'viewed payslip for all employees for period ' . $request->get('period'));
                // return view('payslips.payslips', compact('empall', 'select', 'period', 'currency', 'organization'));
                // return view('pdf.monthlySlip', compact('empall', 'select', 'period', 'currency', 'organization'));
                $pdf = app('dompdf.wrapper')->loadView('pdf.monthlySlip', compact('empall', 'select', 'period', 'currency', 'organization', 'transacts'))->setPaper('a4');
                return $pdf->stream('Payslips.pdf');


            } 
	    else if ($request->get("employeeid") != 'All') {

                $period = $request->get("period");
                $select = $request->get("employeeid");
                $id = $request->get('employeeid');

                $empall = DB::table('x_transact')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('organization_id', Auth::user()->organization_id)
		    ->where('employeeId',$id)
                    ->get();


                $currency = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->first();

                $organization = Organization::find(Auth::user()->organization_id);

                // $type = $request->type;
                // $jgroup = Jobgroup::where(function ($query) {
                //     $query->whereNull('organization_id')
                //         ->orWhere('organization_id', Auth::user()->organization_id);
                // })->where('job_group_name', $type)
                //     ->first();
                $transacts = DB::table('x_transact')
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('organization_id', Auth::user()->organization_id)
		    ->where('employeeId',$id)
                    ->get();

                    // Commented out by dominick Kyengo on 31/08/2023
                    //->where('x_employee.id', '=', $request->get('employeeid'))

                Audit::logaudit(Carbon::now(), 'view', 'viewed payslip for all employees for period ' . $request->get('period'));
                // return view('payslips.payslips', compact('empall', 'select', 'period', 'currency', 'organization'));
                // return view('pdf.monthlySlip', compact('empall', 'select', 'period', 'currency', 'organization'));
                $pdf = app('dompdf.wrapper')->loadView('pdf.monthlySlip', compact('empall', 'select', 'period', 'currency', 'organization', 'transacts'))->setPaper('a4');
                return $pdf->stream('Payslips.pdf');


            }
            else {
                if ($data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get("employeeid"))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->count() == 0) {

                    return Redirect::to('css/payslips')->with('errors', 'Your payslip for period ' . $request->get('period') . ' is not available!');
                } else {
                    $period = $request->get("period");

                    $select = $request->get("employeeid");

                    $id = $request->get('employeeid');

                    $employee = Employee::find($id);

                    $empall = Employee::where('x_employee.organization_id', Auth::user()->organization_id)->get();

                    $name = '';

                    $part = explode("-", $request->get('period'));

                    $m = "";

                    if (strlen($part[0]) == 1) {
                        $m = "0" . $part[0];
                    } else {
                        $m = $part[0];
                    }

                    $month = $m . "_" . $part[1];

                    if ($employee->middle_name == '' || $employee->middle_name == null) {
                        $name = $employee->first_name . ' ' . $employee->last_name;
                    } else {
                        $name = $employee->first_name . ' ' . $employee->middle_name . ' ' . $employee->last_name;
                    }

                    $transacts = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->first();

                    $nontaxables = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('nontaxable_name')
                        ->select('nontaxable_name', DB::raw('COALESCE(sum(nontaxable_amount),0.00) as nontaxable_amount'))
                        ->get();

                    $allws = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('allowance_name')
                        ->select('allowance_name', DB::raw('COALESCE(sum(allowance_amount),0.00) as allowance_amount'))
                        ->get();

                    $earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('earning_name')
                        ->select('earning_name', DB::raw('COALESCE(sum(earning_amount),0.00) as earning_amount'))
                        ->get();

                    $deds = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('deduction_name')
                        ->select('deduction_name', DB::raw('COALESCE(sum(deduction_amount),0.00) as deduction_amount'))
                        ->get();

                    $overtimes = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('overtime_type')
                        ->select('overtime_type', DB::raw('COALESCE(sum(overtime_period*overtime_amount),0.00) as overtimes'))
                        ->get();

                    $rels = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->groupBy('relief_name')
                        ->select('relief_name', DB::raw('COALESCE(sum(relief_amount),0.00) as relief_amount'))
                        ->get();

                    $currency = DB::table('x_currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->first();


                    $pension = DB::table('x_transact_pensions')
                        ->join('x_employee', 'x_transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select(DB::raw('COALESCE(sum(employee_amount),0.00) as employee_amount'))
                        ->first();

                    $organization = Organization::find(Auth::user()->organization_id);

                    Audit::logaudit(Carbon::now(), 'view', 'viewed payslip for ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name . ' for period ' . $request->get('period'));

                    //return view('pdf.monthlySlip', compact('nontaxables','empall','select','name','x_employee','x_transact','allws','deds','earnings','overtimes','pension','rels','period','currency', 'organization','id'));
                    $pdf = app('dompdf.wrapper')->loadView('pdf.monthlySlip', compact('nontaxables', 'empall', 'select', 'name', 'x_employee', 'transacts', 'allws', 'deds', 'earnings', 'overtimes', 'pension', 'rels', 'period', 'currency', 'organization', 'id'))->setPaper('a5');

                    return $pdf->stream($employee->personal_file_number . '_' . $employee->first_name . '_' . $employee->last_name . '_' . $month . '.pdf');
                }
            }
        }
    }

    public function period_summary()
    {
        $branches = Branch::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $depts = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->get();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }
//        if(count($jgroup)>0){
//        }else{
//        }
        return view('pdf.summarySelect', compact('branches', 'depts', 'type'));
    }

    public function paySummary(Request $request)
    {
        if ($request->get('format') == "excel") {
            $sels = DB::table('x_departments')->find($request->get('department'));
            $selBr = DB::table('x_branches')->find($request->get('branch'));
            $selDt = DB::table('x_departments')->find($request->get('department'));
            $selBranch = $request->get("branch");
            $selDept = $request->get("department");
            $period = $request->get("period");
            $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();
            $organization = Organization::find(Auth::user()->organization_id);
            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                if ($request->get('type') == "All") {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }
                else {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_overtime_hourly = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_overtime_daily = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_relief = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }

                $part = explode("-", $request->get('period'));
                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees for period ' . $request->get('period'));
                return Excel::download(new PayrollExport(
                    $total_pay,
                    $total_earning,
                    $total_gross,
                    $total_paye,
                    $total_nssf,
                    $total_nhif,
                    $total_others,
                    $total_deds,
                    $total_net,
                    $data,
                    $data_allowance,
                    $data_nontax,
                    $data_earnings,
                    $data_overtime,
                    $data_overtime_hourly,
                    $data_overtime_daily,
                    $data_relief,
                    $data_deduction,
                    $organization,
                    $currency,
                    $selBranch,
                    $selDept,
                    $period
                ), 'Payroll_summary_' . $month . '.xlsx');
            }
            else if ($request->get('department') == 'All') {

                $sels = DB::table('x_branches')->find($request->get('branch'));

                if ($request->get('department') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                } else {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_overtime_hourly = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_overtime_daily = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $data_relief = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $branch = Branch::find($request->get('branch'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in branch ' . $branch->name . ' for period ' . $request->get('period'));
                /*
                 * Excel
                 * */
                return Excel::download(new PayrollExport(
                    $total_pay,
                    $total_earning,
                    $total_gross,
                    $total_paye,
                    $total_nssf,
                    $total_nhif,
                    $total_others,
                    $total_deds,
                    $total_net,
                    $data,
                    $data_allowance,
                    $data_nontax,
                    $data_earnings,
                    $data_overtime,
                    $data_overtime_hourly,
                    $data_overtime_daily,
                    $data_relief,
                    $data_deduction,
                    $organization,
                    $currency,
                    $selBranch,
                    $selDept,
                    $period
                ), 'Payroll_summary_' . $month . '.xlsx');
            }
            else if ($request->get('branch') == 'All')
            {

                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                } else {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ///->where('process_type', '=', $request->get('type'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $department = Department::find($request->get('department'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in department ' . $department->department_name . ' for period ' . $request->get('period'));
                /*
                 * Excel
                 * */
                return Excel::download(new PayrollExport(
                    $total_pay,
                    $total_earning,
                    $total_gross,
                    $total_paye,
                    $total_nssf,
                    $total_nhif,
                    $total_others,
                    $total_deds,
                    $total_net,
                    $data,
                    $data_allowance,
                    $data_nontax,
                    $data_earnings,
                    $data_overtime,
                    $data_overtime_hourly,
                    $data_overtime_daily,
                    $data_relief,
                    $data_deduction,
                    $organization,
                    $currency,
                    $selBranch,
                    $selDept,
                    $period,
                    $sels
                ), 'Payroll_summary_' . $month . '.xlsx');
            }
            else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {

                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }
                else {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                    $data_allowance = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
                        ->get();

                    $data_nontax = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
                        ->get();

                    $data_earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ///->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
                        ->get();

                    $data_overtime = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                    $data_overtime_hourly = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('overtime_type', '=', 'Hourly')
                        ->get();

                    $data_overtime_daily = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->where('overtime_type', '=', 'Daily')
                        ->get();

                    $data_relief = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
                        ->get();

                    $data_deduction = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        //->where('process_type', '=', $request->get('type'))
                        ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $branch = Branch::find($request->get('branch'));
                $department = Department::find($request->get('department'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in branch ' . $branch->name . ' and department ' . $department->deduction_name . ' for period ' . $request->get('period'));
                /*
                 * Excel
                 * */
                return Excel::download(new PayrollExport(
                    $total_pay,
                    $total_earning,
                    $total_gross,
                    $total_paye,
                    $total_nssf,
                    $total_nhif,
                    $total_others,
                    $total_deds,
                    $total_net,
                    $data,
                    $data_allowance,
                    $data_nontax,
                    $data_earnings,
                    $data_overtime,
                    $data_overtime_hourly,
                    $data_overtime_daily,
                    $data_relief,
                    $data_deduction,
                    $organization,
                    $currency,
                    $selBranch,
                    $selDept,
                    $period,
                    $sels,
                    $selBr,
                    $selDt,
                ), 'Payroll_summary_' . $month . '.xlsx');
            }
        } else {
            $period = $request->get("period");
            $selBranch = $request->get("branch");
            $selDept = $request->get("department");


            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');
                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $currencies = DB::table('x_currencies')
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->orderBy('personal_file_number', 'ASC')
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
			// ->orderByRaw('CAST(personal_file_number as SIGNED INTEGER)', 'ASC')
                } else {
                    $total_pay = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $currencies = DB::table('x_currencies')
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees for period ' . $request->get('period'));

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryReport', compact('sums', 'selBranch', 'selDept', 'total_pay', 'total_earning', 'total_gross', 'total_paye', 'total_nssf', 'total_nhif', 'total_others', 'total_deds', 'total_net', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Payroll_summary_' . $month . '.pdf');

            } else if ($request->get('department') == 'All') {
                $sels = DB::table('x_branches')->find($request->get('branch'));

                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $currencies = DB::table('x_currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('x_branches', 'x_employee.branch_id', '=', 'x_branches.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();

                } else {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $currencies = DB::table('currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                }

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                $branch = Branch::find($request->get('branch'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in branch ' . $branch->name . ' for period ' . $request->get('period'));

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryReport', compact('sums', 'selBranch', 'selDept', 'sels', 'total_pay', 'total_earning', 'total_gross', 'total_paye', 'total_nssf', 'total_nhif', 'total_others', 'total_deds', 'total_net', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Payroll_summary_' . $month . '.pdf');

            } else if ($request->get('branch') == 'All') {
                $sels = DB::table('x_departments')->find($request->get('department'));

                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $currencies = DB::table('x_currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('x_departments', 'x_employee.department_id', '=', 'x_departments.id')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                } else {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $currencies = DB::table('currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                }

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $department = Department::find($request->get('department'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in department ' . $department->deduction_name . ' for period ' . $request->get('period'));

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryReport', compact('sums', 'selBranch', 'selDept', 'sels', 'total_pay', 'total_earning', 'total_gross', 'total_paye', 'total_nssf', 'total_nhif', 'total_others', 'total_deds', 'total_net', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Payroll_summary_' . $month . '.pdf');


            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {
                $selBr = DB::table('branches')->find($request->get('branch'));
                $selDt = DB::table('departments')->find($request->get('department'));

                if ($request->get('type') == 'All') {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $currencies = DB::table('currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                        ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                } else {
                    $total_pay = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('x_transact.basic_pay');

                    $total_earning = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('earning_amount');

                    $total_gross = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('taxable_income');

                    $total_paye = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('paye');

                    $total_nssf = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nssf_amount');

                    $total_nhif = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('nhif_amount');

                    $total_others = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('other_deductions');

                    $total_deds = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('total_deductions');

                    $total_net = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $currencies = DB::table('currencies')
                        ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                        ->select('shortname')
                        ->get();

                    $sums = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->join('branches', 'x_employee.branch_id', '=', 'branches.id')
                        ->join('departments', 'x_employee.department_id', '=', 'departments.id')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'middle_name', 'last_name', 'x_transact.basic_pay', 'taxable_income', 'paye', 'nssf_amount', 'nhif_amount', 'earning_amount', 'relief', 'other_deductions', 'total_deductions', 'net', 'x_employee.id', 'income_tax_applicable', 'income_tax_relief_applicable')
                        ->get();
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $branch = Branch::find($request->get('branch'));
                $department = Department::find($request->get('department'));

                Audit::logaudit(Carbon::now(),'Payroll Summary', 'view', 'viewed payroll summary for all employees in branch ' . $branch->name . ' and department ' . $department->deduction_name . ' for period ' . $request->get('period'));

                $pdf = app('dompdf.wrapper')->loadView('pdf.summaryReport', compact('sums', 'selBranch', 'selDept', 'selBr', 'selDt', 'total_pay', 'total_earning', 'total_gross', 'total_paye', 'total_nssf', 'total_nhif', 'total_others', 'total_deds', 'total_net', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Payroll_summary_' . $month . '.pdf');

            }

        }
    }

    public function period_rem()
    {
        $branches = Branch::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $depts = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->get();
        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->get();
        //$currency = Currency::whereNull('organization_id')->orWhere('organization_id',Auth::user()->organization_id)->first();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }
        return view('pdf.remittanceSelect', compact('branches', 'depts', 'type'));

    }

    public function payeRems(Request $request)
    {

        if ($request->get('format') == "excel") {
            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                } else {

                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();
                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));
                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Remittance Report ' . $month, function ($excel) use ($data, $total, $mont, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Remittance Report', function ($sheet) use ($data, $total, $organization, $mont, $currency, $branch, $bank, $objPHPExcel) {


                        $sheet->mergeCells('A1:F1');

                        $sheet->row(1, array(
                            $organization->name
                        ));
                        $sheet->setWidth(array(
                            'A' => 15,
                            'B' => 35,
                            'C' => 15,
                            'D' => 20,
                            'E' => 15,
                            'F' => 30,
                            'G' => 20,
                            'H' => 10,
                            'I' => 15,
                            'J' => 15,
                            'K' => 10,
                            'L' => 15,
                            'M' => 10


                        ));


                        $sheet->row(1, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });


                        $sheet->row(2, array(
                            'STAFF NO.', 'EMPLOYEE NAME', 'CODE', 'ACCOUNT NO.', 'AMOUNT', 'PAY MTHD', 'DR AC', '', 'MONTH', 'CURRENCY', '', 'SHA', ''
                        ));

                        $sheet->row(2, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 3;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';
                            $idno = '';

                            if ($data[$i]->identity_number == '' || $data[$i]->identity_number == null) {
                                $idno = $data[$i]->work_permit_number;
                            } else {
                                $idno = $data[$i]->identity_number;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }
                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->bank_eft_code, $data[$i]->bank_account_number, $data[$i]->net, 'corporate salary transfer', $organization->bank_account_number, '', $mont, $currency->shortname, '', 'SHA', ''
                            ));
                            $sheet->cells('A' . $row . ':M' . $row, function ($cells) {
                                $cells->setAlignment('left');
                            });

                            /**$sheet->cell('F'.$row, function($cell) {
                             *
                             * // manipulate the cell
                             * $cell->setAlignment('right');
                             *
                             * });**/

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('F' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });
                    });

                })->download('xls');
            } else if ($request->get('department') == 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                } else {

                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();
                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                Excel::create('Remittance Report ' . $month, function ($excel) use ($data, $total, $mont, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Remittance Report', function ($sheet) use ($data, $total, $mont, $organization, $currency, $branch, $bank, $objPHPExcel) {

                        $sheet->mergeCells('A1:F1');

                        $sheet->row(1, array(
                            $organization->name
                        ));
                        $sheet->setWidth(array(
                            'A' => 15,
                            'B' => 35,
                            'C' => 15,
                            'D' => 20,
                            'E' => 15,
                            'F' => 30,
                            'G' => 20,
                            'H' => 10,
                            'I' => 15,
                            'J' => 15,
                            'K' => 10,
                            'L' => 15,
                            'M' => 10


                        ));


                        $sheet->row(1, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });


                        $sheet->row(2, array(
                            'STAFF NO.', 'EMPLOYEE NAME', 'CODE', 'ACCOUNT NO.', 'AMOUNT', 'PAY MTHD', 'DR AC', '', 'MONTH', 'CURRENCY', '', 'SHA', ''));

                        $sheet->row(2, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 3;


                        for ($i = 0; $i < count($data); $i++) {
                            $name = '';
                            $idno = '';

                            if ($data[$i]->identity_number == '' || $data[$i]->identity_number == null) {
                                $idno = $data[$i]->work_permit_number;
                            } else {
                                $idno = $data[$i]->identity_number;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->bank_eft_code, $data[$i]->bank_account_number, $data[$i]->net, 'corporate salary transfer', $organization->bank_account_number, '', $mont, $currency->shortname, '', 'SHA', ''));

                            $sheet->cell('F' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('F' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });
                    });

                })->download('xls');
            } else if ($request->get('branch') == 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                Excel::create('Remittance Report ' . $month, function ($excel) use ($data, $mont, $total, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Remittance Report', function ($sheet) use ($data, $total, $mont, $organization, $currency, $branch, $bank, $objPHPExcel) {

                        $sheet->mergeCells('A1:F1');

                        $sheet->row(1, array(
                            $organization->name
                        ));
                        $sheet->setWidth(array(
                            'A' => 15,
                            'B' => 35,
                            'C' => 15,
                            'D' => 20,
                            'E' => 15,
                            'F' => 30,
                            'G' => 20,
                            'H' => 10,
                            'I' => 15,
                            'J' => 15,
                            'K' => 10,
                            'L' => 15,
                            'M' => 10


                        ));


                        $sheet->row(1, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });

                        $sheet->row(2, array(
                            'STAFF NO.', 'EMPLOYEE NAME', 'CODE', 'ACCOUNT NO.', 'AMOUNT', 'PAY MTHD', 'DR AC', '', 'MONTH', 'CURRENCY', '', 'SHA', ''));

                        $sheet->row(2, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 3;


                        for ($i = 0; $i < count($data); $i++) {
                            $name = '';
                            $idno = '';

                            if ($data[$i]->identity_number == '' || $data[$i]->identity_number == null) {
                                $idno = $data[$i]->work_permit_number;
                            } else {
                                $idno = $data[$i]->identity_number;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->bank_eft_code, $data[$i]->bank_account_number, $data[$i]->net, 'corporate salary transfer', $organization->bank_account_number, '', $mont, $currency->shortname, '', 'SHA', ''));

                            $sheet->cell('F' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('F' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });


                    });

                })->download('xls');
            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $data = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $organization = Organization::find(Auth::user()->organization_id);
                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $branch = DB::table('bank_branches')
                    ->join('organizations', 'bank_branches.organization_id', '=', 'organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('organizations', 'banks.organization_id', '=', 'organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();


                Excel::create('Remittance Report ' . $month, function ($excel) use ($data, $mont, $total, $organization, $currency, $branch, $bank) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Remittance Report', function ($sheet) use ($data, $mont, $total, $organization, $currency, $branch, $bank, $objPHPExcel) {
                        $sheet->mergeCells('A1:H1');

                        $sheet->row(1, array(
                            $organization->name
                        ));
                        $sheet->setWidth(array(
                            'A' => 15,
                            'B' => 35,
                            'C' => 15,
                            'D' => 20,
                            'E' => 15,
                            'F' => 30,
                            'G' => 20,
                            'H' => 10,
                            'I' => 15,
                            'J' => 15,
                            'K' => 10,
                            'L' => 15,
                            'M' => 10


                        ));


                        $sheet->row(1, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');
                            $r->setAlignment('center');
                        });


                        $sheet->row(2, array(
                            'STAFF NO.', 'EMPLOYEE NAME', 'CODE', 'ACCOUNT NO.', 'AMOUNT', 'PAY MTHD', 'DR AC', '', 'MONTH', 'CURRENCY', '', 'SHA', ''));

                        $sheet->row(2, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 3;


                        for ($i = 0; $i < count($data); $i++) {
                            $idno = '';
                            $name = '';

                            if ($data[$i]->identity_number == '' || $data[$i]->identity_number == null) {
                                $idno = $data[$i]->work_permit_number;
                            } else {
                                $idno = $data[$i]->identity_number;
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }
                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->bank_eft_code, $data[$i]->bank_account_number, $data[$i]->net, 'corporate salary transfer', $organization->bank_account_number, '', $mont, $currency->shortname, '', 'SHA', ''));

                            $sheet->cell('F' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });
                        $sheet->cell('F' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });


                    });

                })->download('xls');
            }
        } else {

            $period = $request->get("period");


            if ($request->get('branch') == 'All' && $request->get('department') == 'All') {
                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();


                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('x_organizations', 'bank_branches.organization_id', '=', 'x_organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('x_organizations', 'banks.organization_id', '=', 'x_organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                /*$m = "";

        if(strlen($part[0]) == 1){
          $m = "0".$part[0];
        }else{
          $m = $part[0];
        }

        $month = $m."_".$part[1];*/


                $pdf = app('dompdf.wrapper')->loadView('pdf.remittanceReport', compact('rems', 'branch', 'bank', 'mont', 'total', 'currencies', 'currency', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Pay_Remittance_' . $request->get('period') . '.pdf');

            } else if ($request->get('department') == 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('x_organizations', 'bank_branches.organization_id', '=', 'x_organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('x_organizations', 'banks.organization_id', '=', 'x_organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                /*$m = "";

        if(strlen($part[0]) == 1){
          $m = "0".$part[0];
        }else{
          $m = $part[0];
        }

        $month = $m."_".$part[1];*/

                $pdf = app('dompdf.wrapper')->loadView('pdf.remittanceReport', compact('rems', 'branch', 'bank', 'total', 'mont', 'currency', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Pay_Remittance_' . $request->get('period') . '.pdf');

            } else if ($request->get('branch') == 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('x_organizations', 'bank_branches.organization_id', '=', 'x_organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('x_organizations', 'banks.organization_id', '=', 'x_organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                /*$m = "";

        if(strlen($part[0]) == 1){
          $m = "0".$part[0];
        }else{
          $m = $part[0];
        }

        $month = $m."_".$part[1];*/

                $pdf = app('dompdf.wrapper')->loadView('pdf.remittanceReport', compact('rems', 'total', 'branch', 'mont', 'bank', 'currency', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Pay_Remittance_' . $request->get('period') . '.pdf');

            } else if ($request->get('branch') != 'All' && $request->get('department') != 'All') {

                if ($request->get('type') == 'All') {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();

                } else {
                    $total = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('department_id', '=', $request->get('department'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum('net');

                    $rems = DB::table('x_transact')
                        ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                        ->where('branch_id', '=', $request->get('branch'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('department_id', '=', $request->get('department'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->get();
                }
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $branch = DB::table('bank_branches')
                    ->join('x_organizations', 'bank_branches.organization_id', '=', 'x_organizations.id')
                    ->where('bank_branches.id', '=', $organization->bank_branch_id)
                    ->first();

                $bank = DB::table('banks')
                    ->join('x_organizations', 'banks.organization_id', '=', 'x_organizations.id')
                    ->where('banks.id', '=', $organization->bank_id)
                    ->first();

                $monthtext = $request->get('period');
                /**Method of obtaining textual reresentation of the month .I used 15 to avoid ambiguity btn date and month*/
                $mont = date("F", strtotime("15-" . $monthtext));

                $part = explode("-", $request->get('period'));

                /*$m = "";

        if(strlen($part[0]) == 1){
          $m = "0".$part[0];
        }else{
          $m = $part[0];
        }

        $month = $m."_".$part[1];*/

                $pdf = app('dompdf.wrapper')->loadView('pdf.remittanceReport', compact('rems', 'branch', 'bank', 'mont', 'total', 'currency', 'currencies', 'period', 'organization'))->setPaper('a4', 'landscape');

                return $pdf->stream('Pay_Remittance_' . $request->get('period') . '.pdf');

            }

        }
    }

    public function employee_earnings()
    {
        $earnings = DB::table('x_transact_earnings')
            ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->select(DB::raw('DISTINCT(earning_name) as earning_name'))
            ->get();

        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->first();
        try {
            if (count([$jgroup]) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }

        return view('pdf.earningSelect', compact('earnings', 'type'));
    }

    public function earnings(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('earning') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");
                } else {
                    $data = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m;//."-".$part[1];


                Excel::create('Earnings Report ' . $month, function ($excel) use ($data, $currency, $total, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Earnings', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Earning Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'Earning Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'Earning TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->earning_name, $data[$i]->earning_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('earning');

                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");
                } else {
                    $data = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->sum("earning_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                Excel::create('Earnings Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Earnings', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Earnings Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Earning Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->earning_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            if ($request->get('earning') == 'All') {
                $period = $request->get("period");
//                dd($request->period);
                $type = $request->get("earning");
                if ($request->get('type') == 'All') {

                    $earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'earning_name', 'earning_amount')
                        ->get();

                    $total = DB::table('x_transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");
                } else {
                    $earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'earning_name', 'earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->sum("earning_amount");
                }

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = array_pad(explode("-", $request->get('period')), 2, null);

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }
                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.earningReport', compact('earnings', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Earning_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get("earning");

                if ($request->get('type') == 'All') {
                    $earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");
                } else {
                    $earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $total = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('transact_earnings.earning_name', '=', $request->get('earning'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', $request->get('type'))
                        ->sum("earning_amount");
                }
                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.earningReport', compact('earnings', 'name', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Earning_Report_' . $month . '.pdf');
            }
        }

    }

    public function employee_allowances()
    {

        $allws = DB::table('x_transact_allowances')
            ->where('organization_id', Auth::user()->organization_id)
            ->select(DB::raw('DISTINCT(allowance_name) as allowance_name'))
            ->get();
        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->get();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }

        return view('pdf.allowanceSelect', compact('allws', 'type'));
    }

    public function allowances(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('allowance') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_allowances.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $dataearning = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $dataovertime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("allowance_amount");

                    $totalearning = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");

                } else {
                    $data = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_allowances.financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $dataearning = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $dataovertime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("allowance_amount");

                    $totalearning = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("overtime_amount");
                }

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();


                Excel::create('Allowances Report ' . $month, function ($excel) use ($data, $dataearning, $dataovertime, $total, $totalearning, $totalovertime, $organization, $currency) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Allowances', function ($sheet) use ($data, $dataearning, $dataovertime, $total, $totalearning, $totalovertime, $organization, $currency, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Allowance Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'Allowance Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'ALLOWANCE TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;

                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->allowance_name, $data[$i]->allowance_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }

                        /*for($i = 0; $i<count($dataearning); $i++){

         $ename ='';

         if($dataearning[$i]->middle_name == '' || $dataearning[$i]->middle_name == null){
          $ename= $dataearning[$i]->first_name.' '.$dataearning[$i]->last_name;
        }else{
          $ename=$dataearning[$i]->first_name.' '.$dataearning[$i]->middle_name.' '.$dataearning[$i]->last_name;
        }

        $sheet->row($row, array(
        $dataearning[$i]->personal_file_number,$ename,$dataearning[$i]->earning_name,$dataearning[$i]->earning_amount
        ));

        $sheet->cell('D'.$row, function($cell) {

          // manipulate the cell
           $cell->setAlignment('right');

         });

        $row++;

        }

        for($i = 0; $i<count($dataovertime); $i++){

         $oname = '';

         if($dataovertime[$i]->middle_name == '' || $dataovertime[$i]->middle_name == null){
          $oname= $dataovertime[$i]->first_name.' '.$dataovertime[$i]->last_name;
        }else{
          $oname=$dataovertime[$i]->first_name.' '.$dataovertime[$i]->middle_name.' '.$dataovertime[$i]->last_name;
        }

        $sheet->row($row, array(
        $dataovertime[$i]->personal_file_number,$oname,$dataovertime[$i]->overtime_type,$dataovertime[$i]->overtime_amount
        ));

        $sheet->cell('D'.$row, function($cell) {

          // manipulate the cell
           $cell->setAlignment('right');

         });

        $row++;

        }*/
                        $sheet->row($row, array(
                            '', '', 'Total', $total));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('allowance');

                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_allowances.allowance_name', 'transact_allowances.allowance_amount')
                        ->get();

                    $dataearning = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $dataovertime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->sum("allowance_amount");

                    $totalearning = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $data = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_allowances.allowance_name', 'transact_allowances.allowance_amount')
                        ->get();

                    $dataearning = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $dataovertime = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->sum("allowance_amount");

                    $totalearning = DB::table('transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                }
                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();


                Excel::create('Allowances Report ' . $month, function ($excel) use ($data, $currency, $total, $type, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Allowances', function ($sheet) use ($data, $currency, $total, $type, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Allowance Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Allowance Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->allowance_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {

            if ($request->get('allowance') == 'All') {
                $period = $request->get("period");
                $type = $request->get('allowance');
                if ($request->get('allowance') == 'All') {
                    $allws = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'allowance_name', 'allowance_amount')
                        ->get();


                    $earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'x_transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('x_transact_earnings.financial_month_year', '=', request()->get('period'))
                        ->get();

                    $overtimes = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('x_transact_overtimes.financial_month_year', '=', request()->get('period'))
                        ->get();

                    $totalearning = DB::table('x_transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('x_transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->sum("overtime_amount");

                    $total = DB::table('x_transact_allowances')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->sum("allowance_amount");
                } else {
                    $allws = DB::table('x_transact_allowances')
                        ->join('x_employee', 'x_transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', request()->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'allowance_name', 'allowance_amount')
                        ->get();


                    $earnings = DB::table('x_transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', request()->get('type'))
                        ->where('x_transact_earnings.financial_month_year', '=', request()->get('period'))
                        ->get();

                    $overtimes = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', request()->get('type'))
                        ->where('x_transact_overtimes.financial_month_year', '=', request()->get('period'))
                        ->get();

                    $totalearning = DB::table('x_transact_earnings')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', request()->get('type'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('x_transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->where('process_type', '=', request()->get('type'))
                        ->sum("overtime_amount");


                    $total = DB::table('x_transact_allowances')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->where('process_type', '=', request()->get('type'))
                        ->sum("allowance_amount");
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", request()->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.allowanceReport', compact('allws', 'earnings', 'overtimes', 'totalearning', 'totalovertime', 'period', 'type', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Allowance_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get('allowance');
                if ($request->get('type') == 'All') {
                    $allws = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_allowances.allowance_name', 'transact_allowances.allowance_amount')
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->join('allowances', 'transact_allowances.allowance_id', '=', 'allowances.id')
                        ->where('allowances.id', '=', $request->get('allowance'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("allowance_amount");

                    $earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->join('earnings', 'transact_earnings.earning_id', '=', 'earnings.id')
                        ->where('earnings.id', '=', $request->get('allowance'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $overtimes = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->join('overtimes', 'transact_overtimes.overtime_id', '=', 'overtimes.id')
                        ->where('overtimes.type', '=', $request->get('allowance'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $totalearning = DB::table('transact_earnings')
                        ->join('earnings', 'transact_earnings.earning_id', '=', 'earnings.id')
                        ->where('earnings.id', '=', $request->get('allowance'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->join('overtimes', 'transact_overtimes.overtime_id', '=', 'overtimes.id')
                        ->where('overtimes.type', '=', $request->get('allowance'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $allws = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->where('transact_allowances.allowance_name', '=', $request->get('allowance'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_allowances.allowance_name', 'transact_allowances.allowance_amount')
                        ->get();

                    $total = DB::table('transact_allowances')
                        ->join('x_employee', 'transact_allowances.employee_id', '=', 'x_employee.id')
                        ->join('allowances', 'transact_allowances.allowance_id', '=', 'allowances.id')
                        ->where('allowances.id', '=', $request->get('allowance'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("allowance_amount");

                    $earnings = DB::table('transact_earnings')
                        ->join('x_employee', 'transact_earnings.employee_id', '=', 'x_employee.id')
                        ->join('earnings', 'transact_earnings.earning_id', '=', 'earnings.id')
                        ->where('earnings.id', '=', $request->get('allowance'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_earnings.financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_earnings.earning_name', 'transact_earnings.earning_amount')
                        ->get();

                    $overtimes = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->join('overtimes', 'transact_overtimes.overtime_id', '=', 'overtimes.id')
                        ->where('overtimes.type', '=', $request->get('allowance'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_overtimes.financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $totalearning = DB::table('transact_earnings')
                        ->join('earnings', 'transact_earnings.earning_id', '=', 'earnings.id')
                        ->where('earnings.id', '=', $request->get('allowance'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("earning_amount");

                    $totalovertime = DB::table('transact_overtimes')
                        ->join('overtimes', 'transact_overtimes.overtime_id', '=', 'overtimes.id')
                        ->where('overtimes.type', '=', $request->get('allowance'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                }
                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.allowanceReport', compact('allws', 'earnings', 'overtimes', 'totalearning', 'totalovertime', 'period', 'type', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Allowance_Report_' . $month . '.pdf');
            }
        }

    }

    public function employeenontaxableselect()
    {
        $nontaxables = DB::table('x_transact_nontaxables')
            ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->select(DB::raw('DISTINCT(nontaxable_name) as nontaxable_name'))
            ->get();

        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->get();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }

        return view('pdf.nontaxableSelect', compact('nontaxables', 'type'));
    }

    public function employeenontaxables(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('income') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("nontaxable_amount");

                } else {
                    $data = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("nontaxable_amount");
                }

                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];


                Excel::create('Non Taxable Income Report ' . $month, function ($excel) use ($data, $currency, $total, $organization) {
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Non taxable Income', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Non Taxable Income Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'Non Taxable Income Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'Income TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->nontaxable_name, $data[$i]->nontaxable_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('income');

                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("nontaxable_amount");

                } else {
                    $data = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("nontaxable_amount");
                }

                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                Excel::create('Non Taxable Income Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Non Taxable Income', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Non Taxable Income Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Non Taxable Income Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->nontaxable_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            if ($request->get('income') == 'All') {
                $period = $request->get("period");
                $type = $request->get("income");
                if ($request->get('income') == 'All') {
                    $nontaxables = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'nontaxable_name', 'nontaxable_amount')
                        ->get();

                    $total = DB::table('x_transact_nontaxables')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("nontaxable_amount");
                } else {
                    $nontaxables = DB::table('x_transact_nontaxables')
                        ->join('x_employee', 'x_transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'nontaxable_name', 'nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("nontaxable_amount");
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.nontaxableReport', compact('nontaxables', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Non_Taxable_Income_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get("income");

                if ($request->get('income') == 'All') {
                    $nontaxables = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("nontaxable_amount");
                } else {
                    $nontaxables = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_nontaxables.nontaxable_name', 'transact_nontaxables.nontaxable_amount')
                        ->get();

                    $total = DB::table('transact_nontaxables')
                        ->join('x_employee', 'transact_nontaxables.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_nontaxables.nontaxable_name', '=', $request->get('income'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("nontaxable_amount");
                }
                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.nontaxableReport', compact('nontaxables', 'name', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Non_Taxable_Income_Report_' . $month . '.pdf');
            }
        }

    }

    public function employee_overtimes()
    {
        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->get();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }
        return view('pdf.overtimeSelect', compact('type'));
    }

    public function overtimes(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('overtime') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $data = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];


                Excel::create('Overtimes Report ' . $month, function ($excel) use ($data, $currency, $total, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Overtimes', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Overtime Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'overtime Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'OVERTIME TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->overtime_type, $data[$i]->overtime_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('overtime');

                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $data = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                Excel::create('Overtimes Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Overtimes', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Overtimes Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Overtime Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->overtime_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            if ($request->get('overtime') == 'All') {
                $period = $request->get("period");
//                dd($period);
                $type = $request->get("overtime");

                if ($request->get('type') == 'All') {
                    $overtimes = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'overtime_type', 'overtime_amount')
                        ->get();

                    $total = DB::table('x_transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $overtimes = DB::table('x_transact_overtimes')
                        ->join('x_employee', 'x_transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'overtime_type', 'overtime_amount')
                        ->get();

                    $total = DB::table('x_transact_overtimes')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("overtime_amount");
                }

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.overtimeReport', compact('overtimes', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Overtimes_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get("overtime");
                $name = $type;

                if ($request->get('type') == 'All') {
                    $overtimes = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("overtime_amount");
                } else {
                    $overtimes = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_overtimes.overtime_type', 'transact_overtimes.overtime_amount')
                        ->get();

                    $total = DB::table('transact_overtimes')
                        ->join('x_employee', 'transact_overtimes.employee_id', '=', 'x_employee.id')
                        ->where('overtime_type', '=', $request->get('overtime'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("overtime_amount");
                }
                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.overtimeReport', compact('overtimes', 'name', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Overtime_Report_' . $month . '.pdf');
            }
        }

    }

    public function employee_deductions()
    {
        $deds = DB::table('x_transact_deductions')
            ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->select(DB::raw('DISTINCT(deduction_name) as deduction_name'))
            ->get();

        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->first();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $E) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }
        return view('pdf.deductionSelect', compact('deds', 'type'));
    }

    public function deductions(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('deduction') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("deduction_amount");
                } else {
                    $data = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("deduction_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];


                Excel::create('Deductions Report ' . $month, function ($excel) use ($data, $currency, $total, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Deductions', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Deduction Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'Deduction Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'DEDUCTION TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->deduction_name, $data[$i]->deduction_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('deduction');
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("deduction_amount");
                } else {
                    $data = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("deduction_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                Excel::create('Deductions Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Deductions', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Deduction Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Deduction Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->deduction_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            if ($request->get('deduction') == 'All') {
                $period = $request->get("period");
                $type = $request->get("deduction");

                if ($request->get('type') == 'All') {
                    $deds = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'deduction_name', 'deduction_amount')
                        ->get();

                    $total = DB::table('x_transact_deductions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("deduction_amount");
                } else {
                    $deds = DB::table('x_transact_deductions')
                        ->join('x_employee', 'x_transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'deduction_name', 'deduction_amount')
                        ->get();

                    $total = DB::table('x_transact_deductions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("deduction_amount");
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.deductionReport', compact('deds', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Deduction_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get("deduction");
                if ($request->get('type') == 'All') {
                    $deds = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("deduction_amount");
                } else {
                    $deds = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_deductions.deduction_name', 'transact_deductions.deduction_amount')
                        ->get();

                    $total = DB::table('transact_deductions')
                        ->join('x_employee', 'transact_deductions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_deductions.deduction_name', '=', $request->get('deduction'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("deduction_amount");
                }
                $currencies = DB::table('currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.deductionReport', compact('deds', 'name', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Deduction_Report_' . $month . '.pdf');
            }
        }
    }

    public function employee_pensions()
    {

        /*if ( !Entrust::can('pension_report') ) // Checks the current user
      {
      return Redirect::to('home')->with('notice', 'you do not have access to this resource. Contact your system admin');
      }else{*/
        $employees = Employee::all();
        $branches = Branch::all();
        $departments = Department::all();
        return view('pdf.pensionSelect', compact('employees', 'branches', 'departments'));
// }
    }

    public function pensions(Request $request)
    {
        //$to   = explode("-", $request->get('to'));
        //return $to[0];
        $from = explode("-", $request->get('from'));
        $to = explode("-", $request->get('to'));
//        dd($from[0]);
        if ($request->get('format') == "excel") {
            if ($request->get('employeeid') == 'All') {
                $from = explode("-", $request->get('from'));
                $to = explode("-", $request->get('to'));
                if ($request->get('type') == 'All') {

                    $data = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employer_amount', 'month', 'year', 'transact_pensions.employee_id', 'transact_pensions.financial_month_year')
                        ->get();

                    $total = DB::table('transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                } else {
                    $data = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employer_amount', 'month', 'year', 'transact_pensions.employee_id', 'transact_pensions.financial_month_year')
                        ->get();

                    //return $data;

                    $total = DB::table('transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                //$part = explode("-", $request->get('period'));

                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "-" . $from[1] . $t . "-" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                Audit::logaudit(Carbon::now(),'Pension Report', 'view', 'viewed pension contribution report for period ' . $request->get('period'));

                Excel::create('Pension Contributions Report ' . $month, function ($excel) use ($data, $currency, $total, $organization, $period) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);
                    $excel->sheet('Pension', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel, $period) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));
                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });
                        $sheet->row(2, array(
                            'Report name: ', 'Pension Contributions Report'
                        ));
                        $sheet->cell('A2', function ($cell) {
                            // manipulate the cell
                            $cell->setFontWeight('bold');
                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $period
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:H6');
                        $sheet->row(6, array(
                            'Pension Contributions Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'YEAR', 'MONTH', 'PERSONAL FILE NUMBER', 'x_employee', 'EMPLOYEE CONTRIBUTION', 'EMPLOYEE PERCENTAGE (%)', 'EMPLOYER CONTRIBUTION', 'EMPLOYER PERCENTAGE (%)', 'INTEREST', 'MONTH CONTRIBUTION', 'COMMENTS'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;

                        $cont = 0;
                        $total_interest = 0;
                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }


                            $sheet->row($row, array(
                                $data[$i]->
                                year, date('F', strtotime(date("Y")
                                    . "-" . $data[$i]->month . "-01"))
                            , $data[$i]->personal_file_number,
                                $name, $data[$i]->employee_amount,
                                $data[$i]->employee_percentage,
                                $data[$i]->employer_amount,
                                $data[$i]->employer_percentage,
                                Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year),
                                ($data[$i]->employee_amount + $data[$i]
                                        ->employer_amount + Pensioninterest::getTransactInterest($data[$i]
                                        ->employee_id, $data[$i]->financial_month_year)),
                                Pensioninterest::getTransactComment($data[$i]->employee_id, $data[$i]->financial_month_year)
                            ));

                            $cont = $cont + $data[$i]->employee_amount + $data[$i]->employer_amount + Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year);

                            $total_interest = $total_interest + Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year);

                            $sheet->cell('E' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('G' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('I' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('J' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });
                            $row++;

                        }


                        $sheet->row($row, array(
                            '', '', '', 'Total', $total->total_employee, '', $total->total_employer, '', $total_interest, $cont
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });


                        $sheet->cell('E' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });
                        $sheet->cell('G' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });
                        $sheet->cell('I' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->cell('J' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('employeeid');
                $from = explode("-", $request->get('from'));
                $to = explode("-", $request->get('to'));
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('transact_pensions.employee_id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employer_amount', 'month', 'year', 'financial_month_year', 'employee_id')
                        ->get();

                    $total = DB::table('transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('transact_pensions.employee_id', '=', $request->get('employeeid'))
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();


                } else {
                    $data = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('transact_pensions.employee_id', '=', $request->get('employeeid'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employee_id', 'employer_amount', 'month', 'year', 'financial_month_year')
                        ->get();

                    $total = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_pensions.employee_id', '=', $request->get('employeeid'))
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                }
                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $employee = Employee::find($request->get("employeeid"));

                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "-" . $from[1] . $t . "-" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                Audit::logaudit(Carbon::now(),'Pension Report', 'view', 'viewed pension contribution report for employee ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name . ' for period ' . $request->get('period'));

                Excel::create('Pension Contributions Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization, $employee, $period) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Pension', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel, $employee, $period) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Pension Contributions Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $period
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:F6');
                        $sheet->row(6, array(
                            'Pension Contribution Report for employee ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'YEAR', 'MONTH', 'EMPLOYEE CONTRIBUTION', 'EMPLOYEE PERCENTAGE (%)', 'EMPLOYER CONTRIBUTION', 'EMPLOYER PERCENTAGE (%)', 'INTEREST', 'MONTH CONTRIBUTION', 'COMMENTS'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;

                        $cont = 0;

                        $total_interest = 0;
                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }


                            $sheet->row($row, array(
                                $data[$i]->year, date('F', strtotime(date("Y") . "-" . $data[$i]->month . "-01")), $data[$i]->personal_file_number, $name, $data[$i]->employee_amount, $data[$i]->employee_percentage, $data[$i]->employer_amount, $data[$i]->employer_percentage, Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year), ($data[$i]->employee_amount + $data[$i]->employer_amount + Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year)), Pensioninterest::getTransactComment($data[$i]->employee_id, $data[$i]->financial_month_year)
                            ));

                            $cont = $cont + $data[$i]->employee_amount + $data[$i]->employer_amount + Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year);

                            $total_interest = $total_interest + Pensioninterest::getTransactInterest($data[$i]->employee_id, $data[$i]->financial_month_year);

                            $sheet->cell('E' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('G' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('I' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $sheet->cell('J' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });
                            $row++;

                        }


                        $sheet->row($row, array(
                            '', '', '', 'Total', $total->total_employee, '', $total->total_employer, '', $total_interest, $cont
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->cell('E' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->cell('G' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                        $sheet->cell('H' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else if ($request->get('format') == "pdf") {
            $from = explode("-", $request->get('from'));
            $to = explode("-", $request->get('to'));
            if ($request->get('employeeid') == 'All') {
                $type = "All";

                if ($request->get('type') == 'All') {
                    $pensions = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employee_id', 'employer_amount', 'month', 'year', 'financial_month_year')
                        ->get();

                    //return $data;

                    $total = DB::table('transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                } else {
                    $pensions = DB::table('x_transact_pensions')
                        ->join('x_employee', 'x_transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employee_id', 'employer_amount', 'month', 'year', 'financial_month_year')
                        ->get();

                    //return $data;

                    $total = DB::table('x_transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                //$part = explode("-", $request->get('period'));

                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "_" . $from[1] . $t . "_" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                $organization = Organization::find(Auth::user()->organization_id);

                Audit::logaudit(Carbon::now(),'Pension Report', 'view', 'viewed pension contributions report for period ' . $period);

                $pdf = app('dompdf.wrapper')->loadView('pdf.pensionReport', compact('pensions', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Pension_Contrbutions_Report_' . $month . '.pdf');
            } else {
                $type = $request->get("employeeid");
                if ($request->get('type') == 'All') {
                    $pensions = DB::table('transact_pensions')
                        ->join('x_employee', 'transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('employee_id', $request->get("employeeid"))
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employee_id', 'employer_amount', 'month', 'year', 'financial_month_year')
                        ->get();

                    //return $data;

                    $total = DB::table('transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[1], $to[1]))
                        ->whereBetween('month', array($from[0], $to[0]))
                        ->where('employee_id', $request->get("employeeid"))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                } else {
                    $pensions = DB::table('x_transact_pensions')
                        ->join('x_employee', 'x_transact_pensions.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('employee_id', $request->get("employeeid"))
                        ->whereBetween('year', array($from[0], $to[0]))
                        ->whereBetween('month', array($from[1], $to[1]))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'employee_amount', 'employer_amount', 'employee_percentage', 'employer_percentage', 'employee_id', 'employer_amount', 'month', 'year', 'financial_month_year')
                        ->get();

                    //return $data;

                    $total = DB::table('x_transact_pensions')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->whereBetween('year', array($from[0], $to[0]))
                        ->whereBetween('month', array($from[1], $to[1]))
                        ->where('employee_id', $request->get("employeeid"))
                        ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                        ->first();
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "_" . $from[1] . $t . "_" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                $employee = Employee::find($request->get("employeeid"));

                Audit::logaudit(Carbon::now(),'Pension Report', 'view', 'viewed pension contributions report for employee ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name . ' for period ' . $period);

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.pensionReport', compact('pensions', 'x_employee', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Pension_Contributions_Report_' . $month . '.pdf');
            }
        } else {
            $from = explode("-", $request->get('from'));
            $to = explode("-", $request->get('to'));
            if ($request->get("employeeid") == "All") {
                $pensions = DB::table('transact_pensions')
                    ->whereBetween('year', array($from[1], $to[1]))
                    ->whereBetween('month', array($from[0], $to[0]))
                    ->groupBy('month', 'year')
                    ->selectRaw('sum(employee_amount+employer_amount) as sum, month,year,employee_id,financial_month_year')
                    ->get();

                $m = DB::table("transact_pensions")->groupBy('month', 'year')
                    ->selectRaw('sum(employee_amount+employer_amount) as sum, month,year')
                    ->orderBy('sum')
                    ->first();

                $total = DB::table('transact_pensions')
                    ->where('organization_id', Auth::user()->organization_id)
                    ->whereBetween('year', array($from[1], $to[1]))
                    ->whereBetween('month', array($from[0], $to[0]))
                    ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                    ->first();

                $max = 0;

                if (count($pensions) > 0) {
                    $intr = 0;
                    foreach ($pensions as $deduction) {
                        $intr = $intr + Pensioninterest::getTransactTotalInterest($deduction->financial_month_year);
                    }
                    $max = $m->sum + $intr;
                } else {
                    $max = 0;
                }
                $employee = "All";
                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "_" . $from[1] . $t . "_" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                Audit::logaudit(Carbon::now(),'Pension Graph', 'view', 'viewed pension contributions graph for all employees for period ' . $period);
                return view('pdf.graph', compact('max', 'pensions', 'x_employee', 'total', 'period'));
            } else if ($request->get("from") == "" || $request->get("to") == "") {
                return Redirect::to('payrollReports/selectPension')->withDeleteMessage('Please select period!');
            } else {
                $pensions = DB::table('transact_pensions')
                    ->where('employee_id', $request->get("employeeid"))
                    ->whereBetween('year', array($from[1], $to[1]))
                    ->whereBetween('month', array($from[0], $to[0]))
                    ->groupBy('month', 'year')
                    ->selectRaw('sum(employee_amount+employer_amount) as sum, month,year,employee_id,financial_month_year')
                    ->get();

                $m = DB::table("transact_pensions")->groupBy('month', 'year')
                    ->selectRaw('sum(employee_amount+employer_amount) as sum, month,year')
                    ->where('employee_id', '=', $request->get("employeeid"))
                    ->orderBy('sum')
                    ->first();

                $total = DB::table('transact_pensions')
                    ->where('organization_id', Auth::user()->organization_id)
                    ->whereBetween('year', array($from[1], $to[1]))
                    ->whereBetween('month', array($from[0], $to[0]))
                    ->where('employee_id', $request->get("employeeid"))
                    ->select(DB::raw('COALESCE(SUM(employee_amount),0) as total_employee,COALESCE(SUM(employer_amount),0) as total_employer'))
                    ->first();

                $max = 0;

                if (count($pensions) > 0) {
                    $intr = 0;
                    foreach ($pensions as $deduction) {
                        $intr = $intr + Pensioninterest::getTransactInterest($deduction->employee_id, $deduction->financial_month_year);
                    }
                    $max = $m->sum + $intr;
                } else {
                    $max = 0;
                }
                $employee = Employee::find($request->get("employeeid"));
                $f = "";
                $t = "";


                if (strlen($from[0]) == 1) {
                    $f = "0" . $from[0];
                } else {
                    $f = $from[0];
                }

                if (strlen($to[0]) == 1) {
                    $t = "0" . $to[0];
                } else {
                    $t = $to[0];
                }

                $month = $f . "_" . $from[1] . $t . "_" . $to[1];
                $period = $f . "-" . $from[1] . " to " . $t . "-" . $to[1];

                Audit::logaudit(Carbon::now(),'Pension Graph', 'view', 'viewed pension contributions graph for employee ' . $employee->personal_file_number . ' : ' . $employee->first_name . ' ' . $employee->last_name . ' for period ' . $period);
                return view('pdf.graph', compact('max', 'pensions', 'x_employee', 'total', 'period'));
            }
        }
    }

    public function employee_reliefs()
    {
        $reliefs = DB::table('x_transact_reliefs')
            ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->select(DB::raw('DISTINCT(relief_name) as relief_name'))
            ->get();

        $department = Department::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->where('name', 'Management')->first();

        $jgroup = Jobgroup::where(function ($query) {
            $query->whereNull('organization_id')
                ->orWhere('organization_id', Auth::user()->organization_id);
        })->where('job_group_name', 'Management')
            ->first();
        try {
            if (count($jgroup) > 0) {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->where('job_group_id', $jgroup->id)->where('personal_file_number', Auth::user()->username)->count();
            } else {
                $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
            }
        } catch (\Exception $e) {
            $type = Employee::where('organization_id', Auth::user()->organization_id)->/*where('job_group_id',$jgroup->id)->*/ where('personal_file_number', Auth::user()->username)->count();
        }

        return view('pdf.reliefSelect', compact('reliefs', 'type'));
    }

    public function reliefs(Request $request)
    {
        if ($request->get('format') == "excel") {
            if ($request->get('relief') == 'All') {
                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_reliefs.relief_name', 'transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('transact_reliefs')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("relief_amount");
                } else {
                    $data = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_reliefs.relief_name', 'transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('transact_reliefs')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("relief_amount");
                }
                $organization = Organization::find(Auth::user()->organization_id);

                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];


                Excel::create('Reliefs Report ' . $month, function ($excel) use ($data, $currency, $total, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Reliefs', function ($sheet) use ($data, $total, $currency, $organization, $objPHPExcel) {
                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Relief Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->mergeCells('A6:D6');
                        $sheet->row(6, array(
                            'Relief Report'
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'RELIEF TYPE', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->relief_name, $data[$i]->relief_amount
                            ));

                            $sheet->cell('D' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('D' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            } else {
                $type = $request->get('relief');

                if ($request->get('type') == 'All') {
                    $data = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_reliefs.relief_name', 'transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("relief_amount");
                } else {
                    $data = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_reliefs.relief_name', 'transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('transact_reliefs')
                        ->join('x_employee', 'transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("relief_amount");

                }
                $organization = Organization::find(Auth::user()->organization_id);
                $currency = Currency::whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)->first();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "-" . $part[1];

                Excel::create('Relief Report ' . $month, function ($excel) use ($data, $total, $type, $currency, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Reliefs', function ($sheet) use ($data, $total, $type, $currency, $organization, $objPHPExcel) {

                        $sheet->row(1, array(
                            'Organization Name: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });


                        $sheet->row(2, array(
                            'Report name: ', 'Relief Report'
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(3, array(
                            'Currency: ', $currency->shortname
                        ));

                        $sheet->cell('A3', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(4, array(
                            'Period: ', $request->get('period')
                        ));

                        $sheet->cell('A4', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A6:C6');
                        $sheet->row(6, array(
                            'Relief Report for ' . $type
                        ));

                        $sheet->row(6, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(8, array(
                            'PERSONAL FILE NUMBER', 'x_employee', 'AMOUNT'
                        ));

                        $sheet->row(8, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 9;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $data[$i]->personal_file_number, $name, $data[$i]->relief_amount
                            ));

                            $sheet->cell('C' . $row, function ($cell) {

                                // manipulate the cell
                                $cell->setAlignment('right');

                            });

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', 'Total', $total
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $sheet->cell('C' . $row, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('right');

                        });

                    });

                })->download('xls');
            }
        } else {
            if ($request->get('relief') == 'All') {
                $period = $request->get("period");
                $type = $request->get("relief");

                if ($request->get('type') == 'All') {
                    $reliefs = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'relief_name', 'relief_amount')
                        ->get();

                    $total = DB::table('x_transact_reliefs')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("relief_amount");
                } else {
                    $reliefs = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'relief_name', 'relief_amount')
                        ->get();

                    $total = DB::table('x_transact_reliefs')
                        ->where('organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("relief_amount");
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.reliefReport', compact('reliefs', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Relief_Report_' . $month . '.pdf');
            } else {
                $period = $request->get("period");
                $type = $request->get("relief");

                if ($request->get('type') == 'All') {
                    $reliefs = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'transact_reliefs.relief_name', 'transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('x_transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->sum("relief_amount");
                } else {
                    $reliefs = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_transact_reliefs.relief_name', '=', request()->get('relief'))
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('financial_month_year', '=', request()->get('period'))
                        ->where('process_type', '=', request()->get('type'))
                        ->select('personal_file_number', 'first_name', 'last_name', 'middle_name', 'x_transact_reliefs.relief_name', 'x_transact_reliefs.relief_amount')
                        ->get();

                    $total = DB::table('x_transact_reliefs')
                        ->join('x_employee', 'x_transact_reliefs.employee_id', '=', 'x_employee.id')
                        ->where('x_employee.organization_id', Auth::user()->organization_id)
                        ->where('x_transact_reliefs.relief_name', '=', $request->get('relief'))
                        ->where('financial_month_year', '=', $request->get('period'))
                        ->where('process_type', '=', $request->get('type'))
                        ->sum("relief_amount");
                }
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.reliefReport', compact('reliefs', 'type', 'period', 'currencies', 'total', 'organization'))->setPaper('a4');

                return $pdf->stream('Relief_Report_' . $month . '.pdf');
            }
        }

    }

    public function period_nssf(Request $request)
    {
        $total = DB::table('x_transact')
            ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->where('social_security_applicable', '=', 1)
            //->where('financial_month_year', '=', $request->get('period'))
            ->sum('nssf_amount');

        $data = DB::table('x_transact')
            ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
            ->where('x_employee.organization_id', Auth::user()->organization_id)
            ->where('social_security_applicable', '=', 1)
            //->where('financial_month_year', '=', $request->get('period'))
            ->get();

        $organization = Organization::find(Auth::user()->organization_id);

        $part = explode("-", $request->get('period'));

        $m = "";

        if (strlen($part[0]) == 1) {
            $m = "0" . $part[0];
        } else {
            $m = $part[0];
        }

//        $month = $m . "_" . $part[1];
        return view('pdf.nssfSelect', compact('total', 'data', 'organization', 'part'));
    }

    public function nssfReturns(Request $request)
    {

        if ($request->get('format') == "excel") {
            $total = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('social_security_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->sum('nssf_amount');

            $data = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('social_security_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->get();

            $organization = Organization::find(Auth::user()->organization_id);
            $part = explode("-", $request->get('period'));

            $m = "";

            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }

            $month = $m . "_" . $part[1];

            return Excel::download(new NssfReport($total, $data, $organization, $month), 'nssf_Report_.xls');
        } else {
            $period = $request->get("period");
            $total = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('social_security_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->sum('nssf_amount');
            $currencies = DB::table('x_currencies')
                ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                ->select('shortname')
                ->get();
            $nssfs = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('social_security_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->get();
            $organization = Organization::find(Auth::user()->organization_id);
            $part = explode("-", $request->get('period'));
            $m = "";
            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }
            $month = $m . "_" . $part[1];
            $pdf = app('dompdf.wrapper')->loadView('pdf.nssfReport', compact('nssfs', 'total', 'currencies', 'period', 'organization'))->setPaper('a4');
            return $pdf->stream('nssf_Report_' . $month . '.pdf');
        }
    }

    public function period_nhif()
    {
        return view('pdf.nhifSelect');
    }

    public function nhifReturns(Request $request)
    {
        if (request()->get('format') == "excel") {

            $total = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('hospital_insurance_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->sum('nhif_amount');

            $data = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('hospital_insurance_applicable', '=', 1)
                ->where('financial_month_year', '=', $request->get('period'))
                ->get();

            $organization = Organization::find(Auth::user()->organization_id);
            $part = explode("-", $request->get('period'));
            $m = "";

            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }

            $month = $m . "_" . $part[1];

            $per = $part[1] . "-" . $m;

            return Excel::download(new NhifReport($total, $data, $organization, $month), 'nhif_report.xls');

        } else {
            $period = request()->get("period");

            $total = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('hospital_insurance_applicable', '=', 1)
                ->where('financial_month_year', '=', request('period'))
                ->sum('nhif_amount');

            $currencies = DB::table('x_currencies')
                ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                ->select('shortname')
                ->get();

            $nhifs = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('hospital_insurance_applicable', '=', 1)
                ->where('financial_month_year', '=', request('period'))
                ->get();

            $organization = Organization::find(Auth::user()->organization_id);

            $part = explode("-", request('period'));

            $m = "";

            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }

            $month = $m . "_" . $part[1];


            $pdf = app('dompdf.wrapper')->loadView('pdf.nhifReport', compact('nhifs', 'total', 'currencies', 'period', 'organization'))->setPaper('a4');

            return $pdf->stream('nhif_Report_' . $month . '.pdf');
        }
    }

    public function period_paye()
    {
        return view('pdf.payeSelect');
    }

    public function payeReturns(Request $request)
    {
        if (request()->get('format') == "excel") {
            $total_enabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request('period'))
                ->where('income_tax_applicable', '=', 1)
                ->sum('paye');


            $total_disabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request('period'))
                ->where('income_tax_applicable', '=', 0)
                ->sum('paye');

            $payes_enabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', $request->get('period'))
                ->where('income_tax_applicable', '=', 1)
                ->get();

            $payes_disabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', $request->get('period'))
                ->where('income_tax_applicable', '=', 0)
                ->get();
            $currencies = DB::table('x_currencies')
                ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                ->select('shortname')
                ->get();

            $organization = Organization::find(Auth::user()->organization_id);

            $period = $request->get('period');

            $type = $request->get('type');

            $part = explode("-", $request->get('period'));

            $m = "";

            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }

            $month = $m . "_" . $part[1];

            return Excel::download(new PayeReport($total_enabled, $total_disabled, $payes_enabled, $payes_disabled, $organization, $period, $type, $month, $currencies), 'paye_report.xls');

        } else if (request()->get('format') == "csv") {

            if ($request->get('type') == "enabled") {

                $total_enabled = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', request('period'))
                    ->where('income_tax_applicable', '=', 1)
                    ->sum('paye');


                $total_disabled = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', request('period'))
                    ->where('income_tax_applicable', '=', 0)
                    ->sum('paye');

                $payes_enabled = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('income_tax_applicable', '=', 1)
                    ->get();

                $payes_disabled = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('period'))
                    ->where('income_tax_applicable', '=', 0)
                    ->get();
                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $period = $request->get('period');

                $type = $request->get('type');

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                return Excel::download(new PayeReport($total_enabled, $total_disabled, $payes_enabled, $payes_disabled, $organization, $period, $type, $month, $currencies), 'paye_report.csv');

            } else {

                $period = $request->get('period');

                $data_disabled = DB::table('x_employee')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('income_tax_applicable', '=', 0)
                    ->get();

                $part = explode("-", $request->get('period'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                Excel::create('C_Disabled_Employee_Dtls_' . $month, function ($excel) use ($data_disabled, $period) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('C_Disabled_Employee_Dtls', function ($sheet) use ($data_disabled, $period, $objPHPExcel) {

                        $row = 1;

                        for ($i = 0; $i < count($data_disabled); $i++) {

                            $type = '';
                            $name = '';
                            $ac = '';
                            $mortgage = '';
                            $deposit = '';
                            $relief = '';

                            if ($data_disabled[$i]->type_id == 1) {
                                $type = 'Primary Employee';
                            } else {
                                $type = 'Secondary Employee';
                            }

                            if ($data_disabled[$i]->middle_name != '' && $data_disabled[$i]->middle_name != null) {
                                $name = $data_disabled[$i]->first_name . ' ' . $data_disabled[$i]->middle_name . ' ' . $data_disabled[$i]->last_name;
                            } else {
                                $name = $data_disabled[$i]->first_name . ' ' . $data_disabled[$i]->last_name;
                            }

                            if ($data_disabled[$i]->type_id == 1) {
                                $ac = 0;
                            } else {
                                $ac = '';
                            }

                            if ($data_disabled[$i]->type_id == 1) {
                                $mortgage = 0;
                            } else {
                                $mortgage = '';
                            }

                            if ($data_disabled[$i]->type_id == 1) {
                                $deposit = 0;
                            } else {
                                $deposit = '';
                            }

                            if ($data_disabled[$i]->type_id == 1) {
                                $relief = 1280.00;
                            } else {
                                $relief = '';
                            }

                            $sheet->row($row, array(
                                $data_disabled[$i]->pin, $name, 'Resident', $type, 0, Payroll::processedsalaries($data_disabled[$i]->personal_file_number, $period),
                                Payroll::processedhouseallowances($data_disabled[$i]->id, $period), Payroll::processedtransportallowances($data_disabled[$i]->id, $period),
                                0, Payroll::processedovertimes($data_disabled[$i]->id, $period), 0, 0, Payroll::processedotherallowances($data_disabled[$i]->id, $period), '',
                                0, 0, '', 0, 'Benefit not given', '', '', '', '', '', '', $ac, '', $mortgage, $deposit, '', '', '', '', $relief, Payroll::processedreliefs($data_disabled[$i]->id, $period),
                                '', 0
                            ));
                            $row++;
                        }
                    });

                })->download('csv');

            }

        } else {

            $type = request()->get('type');

            $period = request()->get("period");

            $total_enabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request()->get('period'))
                ->where('income_tax_applicable', '=', 1)
                ->sum('paye');

            $total_disabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request()->get('period'))
                ->where('income_tax_applicable', '=', 0)
                ->sum('paye');
            $currencies = DB::table('x_currencies')
                ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                ->select('shortname')
                ->get();

            $payes_enabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request()->get('period'))
                ->where('income_tax_applicable', '=', 1)
                ->get();

            $payes_disabled = DB::table('x_transact')
                ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                ->where('x_employee.organization_id', Auth::user()->organization_id)
                ->where('financial_month_year', '=', request()->get('period'))
                ->where('income_tax_applicable', '=', 0)
                ->get();

            $part = explode("-", request()->get('period'));

            $m = "";

            if (strlen($part[0]) == 1) {
                $m = "0" . $part[0];
            } else {
                $m = $part[0];
            }

            $month = $m . "_" . $part[1];

            $organization = Organization::find(Auth::user()->organization_id);

            $pdf = app('dompdf.wrapper')->loadView('pdf.payeReport', compact('payes_enabled', 'payes_disabled', 'type', 'total_enabled', 'total_disabled', 'currencies', 'period', 'organization'))->setPaper('a4');

            return $pdf->stream('Paye_Returns_' . $month . '.pdf');
        }
    }

    public function mergeperiod()
    {
        return view('pdf.mergeSelect');
    }

    public function mergestatutory()
    {

        if (request()->get('format') == "excel") {
            if (request()->get('type') == "month") {

                $data = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('social_security_applicable', '=', 1)
                    ->where('financial_month_year', '=', request()->get('periodmonth'))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('periodmonth'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];


                Excel::create('Merged Statutory Report ' . $month, function ($excel) use ($data, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Merged Statutory Report', function (Request $request, $sheet) use ($data, $organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'Period: ', $request->get('periodmonth')
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A3:F3');
                        $sheet->row(3, array(
                            'MERGED STATUTORY REPORT FOR ' . $request->get('periodmonth')
                        ));

                        $sheet->row(4, array(
                            '#', 'PAYROLL NO.', 'EMPLOYEE NAME', 'PAYE AMOUNT', 'NSSF AMOUNT', 'NHIF AMOUNT'
                        ));

                        $sheet->row(3, function ($r) {

                            // call cell manipulation methods
                            $r->setAlignment('center');
                            $r->setFontWeight('bold');

                        });

                        $sheet->row(4, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 5;
                        $totalpaye = 0;
                        $totalnssf = 0;
                        $totalnhif = 0;


                        for ($i = 0; $i < count($data); $i++) {

                            $totalpaye = $totalpaye + $data[$i]->paye;
                            $totalnssf = $totalnssf + $data[$i]->nssf_amount;
                            $totalnhif = $totalnhif + $data[$i]->nhif_amount;

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                ($i + 1), $data[$i]->personal_file_number, $name, $data[$i]->paye, $data[$i]->nssf_amount, $data[$i]->nhif_amount
                            ));

                            $row++;

                        }
                        $sheet->row($row, array(
                            '', '', 'Total', $totalpaye, $totalnssf, $totalnhif
                        ));
                        $sheet->row($row, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                    });

                })->download('xls');
            } else if (request()->get('type') == 'year') {

                $organization = Organization::find(Auth::user()->organization_id);
                Excel::create('Annual Merged Statutory Report ' . $request->get('periodyear'), function ($excel) use ($organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new Spreadsheet();
// Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Annual Merged Statutory Report', function (Request $request, $sheet) use ($organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(2, array(
                            'Period: ', $request->get('periodyear')
                        ));

                        $sheet->cell('A2', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A4:F4');
                        $sheet->row(4, array(
                            'ANNUAL MERGED STATUTORY REPORT FOR YEAR ' . $request->get('periodyear')
                        ));

                        $k = 5;

                        for ($j = 1; $j <= 12; $j++) {


                            $data = Transact::getTransact($j, $request->get('periodyear'));
                            $sheet->mergeCells('A' . ($j + $k) . ':F' . ($j + $k));
                            if ($j == 1) {
                                $sheet->row($j + $k, array(
                                    'JANUARY ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 2) {
                                $sheet->row($j + $k, array(
                                    'FEBRUARY ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 3) {
                                $sheet->row($j + $k, array(
                                    'MARCH ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 4) {
                                $sheet->row($j + $k, array(
                                    'APRIL ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 5) {
                                $sheet->row($j + $k, array(
                                    'MAY ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 6) {
                                $sheet->row($j + $k, array(
                                    'JUNE ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 7) {
                                $sheet->row($j + $k, array(
                                    'JULY ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 8) {
                                $sheet->row($j + $k, array(
                                    'AUGUST ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 9) {
                                $sheet->row($j + $k, array(
                                    'SEPTEMBER ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 10) {
                                $sheet->row($j + $k, array(
                                    'OCTOBER ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 11) {
                                $sheet->row($j + $k, array(
                                    'NOVEMBER ' . $request->get('periodyear')
                                ));
                            } elseif ($j == 12) {
                                $sheet->row($j + $k, array(
                                    'DECEMBER ' . $request->get('periodyear')
                                ));
                            }


                            $sheet->row($j + $k + 1, array(
                                '#', 'PAYROLL NO.', 'EMPLOYEE NAME', 'PAYE AMOUNT', 'NSSF AMOUNT', 'NHIF AMOUNT'
                            ));


                            $sheet->row(4, function ($r) {

                                // call cell manipulation methods
                                $r->setAlignment('center');
                                $r->setFontWeight('bold');

                            });

                            $sheet->row($j + $k + 1, function ($r) {

                                // call cell manipulation methods
                                $r->setAlignment('center');
                                $r->setFontWeight('bold');

                            });

                            $sheet->row($j + $k, function ($r) {

                                // call cell manipulation methods
                                $r->setAlignment('center');
                                $r->setFontWeight('bold');

                            });

                            $row = $j + $k + 2;
                            $totalpaye = 0;
                            $totalnssf = 0;
                            $totalnhif = 0;


                            for ($i = 0; $i < count($data); $i++) {

                                $totalpaye = $totalpaye + $data[$i]->paye;
                                $totalnssf = $totalnssf + $data[$i]->nssf_amount;
                                $totalnhif = $totalnhif + $data[$i]->nhif_amount;

                                $name = '';

                                if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                    $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                                } else {
                                    $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                                }

                                $sheet->row($row, array(
                                    ($i + 1), $data[$i]->personal_file_number, $name, $data[$i]->paye, $data[$i]->nssf_amount, $data[$i]->nhif_amount
                                ));

                                $row++;

                            }
                            $sheet->row($row, array(
                                '', '', 'Total', $totalpaye, $totalnssf, $totalnhif
                            ));
                            $sheet->row($row, function ($r) {

                                // call cell manipulation methods
                                $r->setFontWeight('bold');

                            });


                            $k = $row - $j + 2;

                        }

                    });

                })->download('xls');
            }

        } else {
            if (request()->get('type') == 'month') {

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $statutories = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->where('financial_month_year', '=', $request->get('periodmonth'))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $part = explode("-", $request->get('periodmonth'));

                $m = "";

                if (strlen($part[0]) == 1) {
                    $m = "0" . $part[0];
                } else {
                    $m = $part[0];
                }

                $month = $m . "_" . $part[1];

                $period = "";

                if ($part[0] == 01) {
                    $period = 'JANUARY ' . $part[1];
                } else if ($part[0] == 02) {
                    $period = 'FEBRUARY ' . $part[1];
                } else if ($part[0] == 03) {
                    $period = 'MARCH ' . $part[1];
                } else if ($part[0] == 04) {
                    $period = 'APRIL ' . $part[1];
                } else if ($part[0] == 05) {
                    $period = 'MAY ' . $part[1];
                } else if ($part[0] == 06) {
                    $period = 'JUNE ' . $part[1];
                } else if ($part[0] == 07) {
                    $period = 'JULY ' . $part[1];
                } else if ($part[0] == 8) {
                    $period = 'AUGUST ' . $part[1];
                } else if ($part[0] == 9) {
                    $period = 'SEPTEMBER ' . $part[1];
                } else if ($part[0] == 10) {
                    $period = 'OCTOBER ' . $part[1];
                } else if ($part[0] == 11) {
                    $period = 'NOVEMBER ' . $part[1];
                } else if ($part[0] == 12) {
                    $period = 'DECEMBER ' . $part[1];
                }

                $pdf = app('dompdf.wrapper')->loadView('pdf.mergedStatutoryReport', compact('statutories', 'currencies', 'period', 'organization'))->setPaper('a4');

                return $pdf->stream('Merged_Statutory_Report_' . $month . '.pdf');

            } else if (request()->get('type') == 'year') {

                $period = request()->get('periodyear');

                $currencies = DB::table('x_currencies')
                    ->whereNull('organization_id')->orWhere('organization_id', Auth::user()->organization_id)
                    ->select('shortname')
                    ->get();

                $statutories = DB::table('x_transact')
                    ->join('x_employee', 'x_transact.employee_id', '=', 'x_employee.personal_file_number')
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->get();


                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.mergedYearStatutoryReport', compact('statutories', 'currencies', 'period', 'organization'))->setPaper('a4');

                return $pdf->stream('Annual_Merged_Statutory_Report_' . request()->get('periodyear') . '.pdf');


            }
        }
    }

    public function getDownload()
    {
        $file = public_path() . "/templates/P10_Return_version_8.0_21032016093001.xlsm";
        return Response::download($file, 'P10_Return_version_8.0_21032016093001.xlsm');
    }

    public function selempkin()
    {
        $employees = Employee::where('x_employee.organization_id', Auth::user()->organization_id)->get();
        return View::make('pdf.selectKinEmployee', compact('employees'));
    }

    public function selstate()
    {
        return View::make('pdf.selectStateEmployee');
    }

    public function selEmp()
    {
        $employees = Employee::where('organization_id', Auth::user()->organization_id)->get();

        return View::make('pdf.selectEmployee', compact('employees'));
    }


    public function appraisalperiod()
    {
        $employees = Employee::where('organization_id', Auth::user()->organization_id)->get();
        return View::make('pdf.selectAppraisalPeriod', compact('employees'));
    }

    public function appraisal()
    {

        if (Input::get('format') == "excel") {
            if (Input::get('employeeid') == 'All') {
                $from = Input::get("from");
                $to = Input::get("to");

                $data = DB::table('appraisals')
                    ->join('x_employee', 'appraisals.employee_id', '=', 'x_employee.id')
                    ->join('appraisalquestions', 'appraisals.appraisalquestion_id', '=', 'appraisalquestions.id')
                    ->join('users', 'appraisals.examiner', '=', 'users.id')
                    ->whereBetween('appraisaldate', array($from, $to))
                    ->where('x_employee.organization_id', Confide::user()->organization_id)
                    ->select('first_name', 'appraisalquestions.rate as score', 'last_name', 'middle_name', 'comment', 'appraisals.rate', 'username', 'question', 'performance', 'appraisaldate')
                    ->get();

                $organization = Organization::find(Confide::user()->organization_id);


                Excel::create('Appraisal Report', function ($excel) use ($data, $from, $to, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new PHPExcel();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Appraisal Report', function ($sheet) use ($data, $from, $to, $organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A3:H3');
                        $sheet->row(3, array(
                            'Appraisal Report for period between ' . $from . ' and ' . $to
                        ));

                        $sheet->row(3, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            '#', 'x_employee', 'QUESTION', 'PERFORMANCE', 'RATE', 'EXAMINER', 'APPRAISAL DATE', 'COMMENT'
                        ));

                        $sheet->row(5, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 6;
                        $x = 1;


                        for ($i = 0; $i < count($data); $i++) {

                            $name = '';

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $x, $name, $data[$i]->question, $data[$i]->performance, $data[$i]->rate . '/' . $data[$i]->score, $data[$i]->username, $data[$i]->appraisaldate, $data[$i]->comment
                            ));
                            $x++;
                            $row++;
                        }


                    });

                })->download('xls');

            } else {
                $id = Input::get('employeeid');

                $from = Input::get("from");
                $to = Input::get("to");

                $employee = Employee::find($id);

                $data = DB::table('appraisals')
                    ->join('x_employee', 'appraisals.employee_id', '=', 'x_employee.id')
                    ->join('appraisalquestions', 'appraisals.appraisalquestion_id', '=', 'appraisalquestions.id')
                    ->join('users', 'appraisals.examiner', '=', 'users.id')
                    ->where('employee_id', $id)
                    ->where('x_employee.organization_id', Confide::user()->organization_id)
                    ->whereBetween('appraisaldate', array($from, $to))
                    ->select('first_name', 'last_name', 'appraisalquestions.rate as score', 'middle_name', 'comment', 'appraisals.rate', 'username', 'question', 'performance', 'appraisaldate')
                    ->get();

                $organization = Organization::find(Confide::user()->organization_id);


                Excel::create('Appraisal Report', function ($excel) use ($data, $from, $to, $employee, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new PHPExcel();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Appraisal Report', function ($sheet) use ($data, $from, $to, $employee, $organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A3:G3');

                        $name = '';
                        if ($employee->middle_name == '' || $employee->middle_name == null) {
                            $name = $employee->first_name . ' ' . $employee->last_name;
                        } else {
                            $name = $employee->first_name . ' ' . $employee->middle_name . ' ' . $employee->last_name;
                        }
                        $sheet->row(3, array(
                            'Appraisal Report for ' . $name . ' for period between ' . $from . ' and ' . $to
                        ));

                        $sheet->row(3, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            '#', 'QUESTION', 'PERFORMANCE', 'RATE', 'EXAMINER', 'APPRAISAL DATE', 'COMMENT'
                        ));

                        $sheet->row(5, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 6;
                        $x = 1;


                        for ($i = 0; $i < count($data); $i++) {

                            $sheet->row($row, array(
                                $x, $data[$i]->question, $data[$i]->performance, $data[$i]->rate . '/' . $data[$i]->score, $data[$i]->username, $data[$i]->appraisaldate, $data[$i]->comment
                            ));
                            $x++;
                            $row++;
                        }


                    });

                })->download('xls');
            }

        } else {
            if (Input::get('employeeid') == 'All') {

                $from = Input::get("from");
                $to = Input::get("to");

                $appraisals = DB::table('appraisals')
                    ->join('x_employee', 'appraisals.employee_id', '=', 'x_employee.id')
                    ->join('appraisalquestions', 'appraisals.appraisalquestion_id', '=', 'appraisalquestions.id')
                    ->join('users', 'appraisals.examiner', '=', 'users.id')
                    ->whereBetween('appraisaldate', array($from, $to))
                    ->where('x_employee.organization_id', Confide::user()->organization_id)
                    ->select('first_name', 'last_name', 'appraisalquestions.rate as score', 'middle_name', 'comment', 'appraisals.rate', 'username', 'question', 'performance', 'appraisaldate')
                    ->get();

                $organization = Organization::find(Confide::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.appraisal', compact('from', 'to', 'organization', 'appraisals'))->setPaper('a4')->setOrientation('potrait');

                //dd($organization);

                return $pdf->stream('appraisal.pdf');

            } else {

                $id = Input::get('employeeid');

                $from = Input::get("from");
                $to = Input::get("to");

                $employee = Employee::find($id);

                $appraisals = DB::table('appraisals')
                    ->join('x_employee', 'appraisals.employee_id', '=', 'x_employee.id')
                    ->join('appraisalquestions', 'appraisals.appraisalquestion_id', '=', 'appraisalquestions.id')
                    ->join('users', 'appraisals.examiner', '=', 'users.id')
                    ->where('employee_id', $id)
                    ->where('x_employee.organization_id', Confide::user()->organization_id)
                    ->whereBetween('appraisaldate', array($from, $to))
                    ->select('first_name', 'last_name', 'middle_name', 'appraisalquestions.rate as score', 'comment', 'appraisals.rate', 'username', 'question', 'performance', 'appraisaldate')
                    ->get();

                $organization = Organization::find(Confide::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.individualappraisal', compact('from', 'to', 'x_employee', 'organization', 'appraisals'))->setPaper('a4')->setOrientation('potrait');

                //dd($organization);

                return $pdf->stream($employee->first_name . '_' . $employee->last_name . '_appraisal.pdf');
            }
        }

    }

    public function propertyperiod()
    {

        $employees = Employee::where('organization_id', Auth::user()->organization_id)->get();
        return View::make('pdf.selectPropertyPeriod', compact('employees'));
    }

    public function property(Request $request)
    {

        if ($request->get('format') == "excel") {
            if ($request->get('employeeid') == 'All') {
                $from = $request->get("from");
                $to = $request->get("to");

                $data = DB::table('properties')
                    ->join('x_employee', 'properties.employee_id', '=', 'x_employee.id')
                    ->where('organization_id', Auth::user()->organization_id)
                    ->whereBetween('issue_date', array($from, $to))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);


                Excel::create('Company Property Report', function ($excel) use ($data, $from, $to, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new PHPExcel();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Company Property Report', function ($sheet) use ($data, $from, $to, $organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A3:L3');
                        $sheet->row(3, array(
                            'Company Property Report for period between ' . $from . ' and ' . $to
                        ));

                        $sheet->row(3, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            '#', 'x_employee', 'PROPERTY NAME', 'DESCRIPTION', 'SERIAL NO.', 'DIGITAL SNO.', 'VALUE', 'ISSUED BY', 'ISSUE DATE', 'SCHEDULED RETURN DATE', 'STATUS', 'RECEIVED BY'
                        ));

                        $sheet->row(5, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 6;
                        $x = 1;


                        for ($i = 0; $i < count($data); $i++) {

                            $status = '';
                            $receiver = '';
                            $name = '';
                            if ($data[$i]->state == 0) {
                                $status = 'Not Returned';
                            } else {
                                $status = 'Returned';
                            }

                            if ($data[$i]->received_by == 0) {
                                $receiver = '';
                            } else {
                                $receiver = Property::getReceiver($data[$i]->received_by);
                            }

                            if ($data[$i]->middle_name == '' || $data[$i]->middle_name == null) {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->last_name;
                            } else {
                                $name = $data[$i]->first_name . ' ' . $data[$i]->middle_name . ' ' . $data[$i]->last_name;
                            }

                            $sheet->row($row, array(
                                $x, $name, $data[$i]->name, $data[$i]->description, $data[$i]->serial, $data[$i]->digitalserial, $data[$i]->monetary, Property::getIssuer($data[$i]->issued_by), $data[$i]->issue_date, $data[$i]->scheduled_return_date, $status, $receiver
                            ));
                            $x++;
                            $row++;
                        }


                    });

                })->download('xls');

            } else {
                $id = $request->get('employeeid');

                $from = $request->get("from");
                $to = $request->get("to");

                $employee = Employee::find($id);

                $data = DB::table('x_properties')
                    ->join('x_employee', 'x_properties.employee_id', '=', 'x_employee.id')
                    ->where('employee_id', $id)
                    ->where('organization_id', Auth::user()->organization_id)
                    ->whereBetween('issue_date', array($from, $to))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);


                Excel::create('Company Property Report', function ($excel) use ($data, $from, $to, $employee, $organization) {

                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/NamedRange.php");
                    require_once(base_path() . "/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php");


                    $objPHPExcel = new PHPExcel();
                    // Set the active Excel worksheet to sheet 0
                    $objPHPExcel->setActiveSheetIndex(0);


                    $excel->sheet('Company Property Report', function ($sheet) use ($data, $from, $to, $employee, $organization, $objPHPExcel) {


                        $sheet->row(1, array(
                            'Organization: ', $organization->name
                        ));

                        $sheet->cell('A1', function ($cell) {

                            // manipulate the cell
                            $cell->setFontWeight('bold');

                        });

                        $sheet->mergeCells('A3:K3');

                        $name = '';

                        if ($employee->middle_name == '' || $employee->middle_name == null) {
                            $name = $employee->first_name . ' ' . $employee->last_name;
                        } else {
                            $name = $employee->first_name . ' ' . $employee->middle_name . ' ' . $employee->last_name;
                        }
                        $sheet->row(3, array(
                            'Company Property Report for ' . $name . ' for period between ' . $from . ' and ' . $to
                        ));

                        $sheet->row(3, function ($cell) {

                            // manipulate the cell
                            $cell->setAlignment('center');
                            $cell->setFontWeight('bold');

                        });

                        $sheet->row(5, array(
                            '#', 'PROPERTY NAME', 'DESCRIPTION', 'SERIAL NO.', 'DIGITAL SNO.', 'VALUE', 'ISSUED BY', 'ISSUE DATE', 'SCHEDULED RETURN DATE', 'STATUS', 'RECEIVED BY'
                        ));

                        $sheet->row(5, function ($r) {

                            // call cell manipulation methods
                            $r->setFontWeight('bold');

                        });

                        $row = 6;
                        $x = 1;


                        for ($i = 0; $i < count($data); $i++) {

                            $status = '';
                            $receiver = '';
                            if ($data[$i]->state == 0) {
                                $status = 'Not Returned';
                            } else {
                                $status = 'Returned';
                            }

                            if ($data[$i]->received_by == 0) {
                                $receiver = '';
                            } else {
                                $receiver = Property::getReceiver($data[$i]->received_by);
                            }
                            $sheet->row($row, array(
                                $x, $data[$i]->name, $data[$i]->description, $data[$i]->serial, $data[$i]->digitalserial, $data[$i]->monetary, Property::getIssuer($data[$i]->issued_by), $data[$i]->issue_date, $data[$i]->scheduled_return_date, $status, $receiver
                            ));
                            $x++;
                            $row++;
                        }


                    });

                })->download('xls');
            }

        } else {

            if ($request->get('employeeid') == 'All') {

                $from = $request->get("from");
                $to = $request->get("to");

                $properties = DB::table('x_properties')
                    ->join('x_employee', 'x_properties.employee_id', '=', 'x_employee.id')
                    ->whereBetween('issue_date', array($from, $to))
                    ->where('organization_id', Auth::user()->organization_id)
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.property', compact('from', 'to', 'organization', 'properties'))->setPaper('a4')->setOrientation('potrait');

                //dd($organization);

                return $pdf->stream('company_property.pdf');

            } else {

                $id = $request->get('employeeid');

                $from = $request->get("from");
                $to = $request->get("to");

                $employee = Employee::find($id);

                $properties = DB::table('x_properties')
                    ->join('x_employee', 'x_properties.employee_id', '=', 'x_employee.id')
                    ->where('employee_id', $id)
                    ->where('x_employee.organization_id', Auth::user()->organization_id)
                    ->whereBetween('issue_date', array($from, $to))
                    ->get();

                $organization = Organization::find(Auth::user()->organization_id);

                $pdf = app('dompdf.wrapper')->loadView('pdf.individualproperty', compact('from', 'to', 'x_employee', 'organization', 'properties'))->setPaper('a4');

                //dd($organization);

                return $pdf->stream($employee->first_name . '_' . $employee->last_name . '_company_property.pdf');
            }
        }

    }

    public function p9form()
    {
        $organization = Organization::find(Auth::user()->organization_id);

        $employee = Employee::find(request('employeeid'));

        $year = request('period');
        $ename = '';

        if ($employee->middle_name != null || $employee->middle_name != '') {
            $ename = $employee->first_name . '_' . $employee->middle_name . '_' . $employee->last_name;
        } else {
            $ename = $employee->first_name . '_' . $employee->last_name;
        }
        return Excel::download(function ($excel) {
            $excel->setTitle('Our New P9 Form');
            $excel->setCreator('Nelon')
                ->setCompany('Lixnet');
            $excel->setDescription("A demo");
        }, 'xls.xls');
        return view('pdf.p9');
    }

    public function p9form1(Request $request)
    {
        $organization = Organization::find(Auth::user()->organization_id);

        $employee = Employee::find(request('employeeid'));
        $year = request('period');

        $ename = '';

        if ($employee->middle_name != null || $employee->middle_name != '') {
            $ename = $employee->first_name . '_' . $employee->middle_name . '_' . $employee->last_name;
        } else {
            $ename = $employee->first_name . '_' . $employee->last_name;
        }
        if ($request->type == "Excel") {
            return Excel::download(new P9FormExports($year, $employee, $organization), $ename . '_P9Form_' . $year . ".xls");
        } else {
            $pdf = app('dompdf.wrapper')->loadview('pdf.p9Pdf', compact('organization', 'x_employee', 'year'));
            return $pdf->stream($employee->first_name . '_' . $employee->last_name . '_' . $year);
        }
    }

}
