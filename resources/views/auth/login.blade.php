@extends('layouts.app')

@section('content')
    <section class="mx-auto flex min-h-[calc(100vh-2rem)] max-w-md flex-col justify-center py-10">
        <h1 class="text-2xl font-semibold text-base-content">{{ __('racklab.auth.login_title') }}</h1>

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf

            <label class="form-control">
                <span class="label-text">{{ __('racklab.auth.email') }}</span>
                <input class="input input-bordered" type="email" name="email" value="{{ old('email') }}" required autofocus>
                @error('email')
                    <span class="mt-1 text-sm text-error">{{ $message }}</span>
                @enderror
            </label>

            <label class="form-control">
                <span class="label-text">{{ __('racklab.auth.password') }}</span>
                <input class="input input-bordered" type="password" name="password" required>
                @error('password')
                    <span class="mt-1 text-sm text-error">{{ $message }}</span>
                @enderror
            </label>

            <button type="submit" class="btn btn-primary w-full">{{ __('racklab.auth.login') }}</button>
        </form>
    </section>
@endsection
