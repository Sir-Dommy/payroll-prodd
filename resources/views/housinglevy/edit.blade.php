@extends('layouts.main_hr')
@section('xara_cbs')
    @include('partials.breadcrumbs')
    <div class="pcoded-inner-content">
        <div class="main-body">
            <div class="page-wrapper">
                <div class="page-body">
                    <div class="row">
                        <div class="col-lg-12">
                            <h3>Update housing levy</h3>

                            <hr>
                        </div>
                        <div class="col-lg-12">
                            <div class="card">
                                <div class="card-body">
                                    @if ($errors)
                                        @foreach ($errors->all() as $error)
                                            <div class="alert alert-danger">
                                                {{ $error }}<br>
                                            </div>
                                        @endforeach
                                    @endif
                                    <form method="PUT" action="{{{ URL::to('housinglevy/update/'.$hrate->id) }}}"
                                          accept-charset="UTF-8">
                                        @csrf
                                        <fieldset>

                                            <div class="form-group">
                                                <label for="username">percentage <span
                                                        style="color:red">*</span></label>
                                                <input class="form-control" placeholder="" type="text" name="percentage"
                                                       id="percentage"
                                                       value="{{ $hrate->percentage}}">
                                            </div>
                                            <div class="form-actions form-group">

                                                <button type="submit" class="btn btn-primary btn-sm">Update housing levy
                                                </button>
                                            </div>

                                        </fieldset>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop
