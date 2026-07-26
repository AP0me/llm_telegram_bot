<form method="POST" action="{{ route('register') }}">
    @csrf

    <!-- Name -->
    <div>
        <label for="name">Full Name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
        @error('name')
            <span class="error-msg">{{ $message }}</span>
        @enderror
    </div>

    <!-- Email Address -->
    <div>
        <label for="email">Email Address</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        @error('email')
            <span class="error-msg">{{ $message }}</span>
        @enderror
    </div>

    <!-- Password -->
    <div>
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        @error('password')
            <span class="error-msg">{{ $message }}</span>
        @enderror
    </div>

    <!-- Confirm Password -->
    <div>
        <label for="password_confirmation">Confirm Password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
    </div>

    <button type="submit">Create Account</button>
</form>

