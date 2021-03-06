<h4>{{ xe_trans('xe::registerByEmailConfirm') }}</h4>

<form action="{{ route('auth.register') }}" method="get">
    <div class="auth-group">
        <input type="hidden" name="token" value="free">
        <label for="email" class="xe-sr-only">{{xe_trans('xe::email')}}</label>
        <input type="text" id="email" class="xe-form-control" placeholder="{{xe_trans('xe::email')}}" name="email" value="{{ old('email') }}">
        <em class="text-message">{{ xe_trans('xe::registerByEmailConfirmDescription') }}</em>

    </div>
    <button type="submit" class="xe-btn xe-btn-primary xe-btn-block">{{ xe_trans('xe::sendConfirmationEmail') }}</button>
</form>
