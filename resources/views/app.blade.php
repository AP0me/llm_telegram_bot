<div>
    <style>
        .logout-form {
            margin: 0;
            display: inline;
        }
    </style>
    <nav>
        <a href="{{ route('chat') }}">
            @if(Auth::check())
                <button>Chat</button>
            @else
                <button disabled>Chat</button>
            @endif
        </a>
        <a href="{{ route('register') }}">
            @if(!Auth::check())
                <button>Register</button>
            @else
                <button disabled>Register</button>
            @endif
        </a>
        <a href="{{ route('login') }}">
            @if(!Auth::check())
                <button>Login</button>
            @else
                <button disabled>Login</button>
            @endif
        </a>
        <form class="logout-form" method="POST" action="{{ route('logout') }}">
            @csrf
            @if(Auth::check())
                <button>Logout</button>
            @else
                <button disabled>Log out</button>
            @endif
        </form>
    </nav>
    @yield('body')
</div>
