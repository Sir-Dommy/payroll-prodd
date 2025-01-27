<?php
$transactions = DB::table('x_transact')
    ->where('financial_month_year', 'LIKE', '%' . $year)
    ->where('organization_id', $organization->id)
    ->where('employeeId', $employee->id)
    ->orderBy('financial_month_year', 'asc')
    ->get();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-B4gt1pOupzpwat3uBbFvB18foO0vcyK0upC3sLcDcYLQLiDZwMhqy4pzw2R4lJ1w" crossorigin="anonymous">
</head>
<style>
        .table-container {
            overflow-x: auto; 
        }

        @media print {
            body {
                font-size: 12px; 
            }

            .table th,
            .table td {
                padding: 5px;
            }

            .card {
                page-break-before: always; 
            }
        }
    </style>
<body>
        <center>
            <img src="https://www.kra.go.ke/templates/kra/images/kra/logo.png" alt="logo" class="img-fluid">
            <P>Kenya Revenue Authority</P>
            <P>Domestic Taxes Department</P>
            <P>Tax Deduction Card <b>{{$year}}</b></P>
        </center>

        <div class="row">
            <div class="col-lg-6">
                <table class="table table-bordered">
                    <tr>
                        
                        <th>
                            Employer Name: {{$organization->name}}
                        </th>
                        <th style="text-align: right">
                            Employer's Pin: {{$organization->tax_number_1}}
                        </th>
                        
                    </tr>
                    <tr>
                        <th>
                            Employee's Main Name: {{$employee->first_name ." ". $employee->last_name}}
                        </th>
                    </tr>
                    <tr >
                        <th>
                            Employee's Other Names: {{$employee->middle_name}}
                        </th>
                        <th style="text-align: right">
                            Employee's pin {{$employee->pin}}
                        </th>
                    </tr>
                </table>
            </div>
        </div>

    <div class="table-container">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="md-table-content" style="overflow-x: auto;">
                    <div class="table-body" style="width: 500px">
                        <table id="data" class="table table-condensed table-bordered table-hover">
                            <tr>
                                <th>Month</th>
                                <th>
                                    Basic Salary
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Benefits Non Cash
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Value of Quarters
                                    <p>Ksh</p>
                                </th>
                                <th style="text-align: justify; width: 90px">
                                    Total Gross Pay
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Defined Contribution
                                    <br/>
                                    Retirement Scheme
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Owner Occupied Interest
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Retirement
                                    <br>
                                    Contribution &
                                    <br>
                                    Owner Occupied
                                    <br>
                                    Interest
                                    <p>Ksh</p>
                                </th>
                                <th>
                                    Chargeable Pay
                                    <p>Ksh.</p>
                                </th>
                                <th>
                                    Tax Charged
                                    <p>Ksh.</p>
                                </th>
                                <th>
                                    Personal Relief
                                    <p>Ksh.</p>
                                </th>
                                <th>
                                    Insurance Relief
                                    <p>Ksh.</p>
                                </th>
                                <th>
                                    Paye Tax
                                    <br>
                                    (J-K)
                                    <p>Ksh.</p>
                                </th>
                            </tr>


                        <!--          Table rows containing data                    -->
                            @foreach($transactions as $transaction)
                                <tr>
                                    <td>{{$transaction->financial_month_year}}</td>
                                    <td>{{$transaction->basic_pay}}</td>

                                    <td>{{$transaction->relief}}</td>
                                    <td>{{'undetermined'}}</td>
                                    <td>{{(double)App\Models\Payroll::gross($employee->id,$transaction->financial_month_year)}}</td>
                                    <td>{{$transaction->nssf_amount}}</td>
                                    <td>{{'undetermined'}}</td>
                                    <td>{{'undetermined'}}</td>
                                    <td>{{$transaction->taxable_income}}</td>
                                    <td>{{$transaction->gross_tax}}</td>
                                    <td>{{$transaction->relief}}</td>
                                    <td>{{$transaction->insurance_relief}}</td>
                                    <td>{{$transaction->paye}}</td>
                                </tr>
                            @endforeach

                            //
                        </table>
                     </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.1/dist/umd/popper.min.js" integrity="sha384-SWEEWgnZkS5L0r3yAAdVT5M0E7AQdX1TVOU82H/bR2Pr5rA/YauFIM1KI+T5lC6Z" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-OU6eV6U2nQI3nOU5lDMOifG5cL0stR8FgCsp5dD/ByW1xt2tUdm8v95t9zHo5lMG9" crossorigin="anonymous"></script>
</body>
</html>
