{{-- Local development only: see config/login-link.php. --}}
<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    @foreach ($users as $user)
        <x-login-link :email="$user->email" :label="'Log in as '.$user->email" />
    @endforeach
</div>
