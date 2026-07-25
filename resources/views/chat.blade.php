<div>
    <iframe id="model_output_frame" src=""></iframe>
    <hr>
    <textarea id="new_user_text" rows="10" cols="50" name="new_user_text"></textarea>
    <button onclick="sendNewUserText()">Send</button>
    <input type="hidden" id="generate_url" value="{{ route('generate') }}">
    <script>
        function sendNewUserText() {
            model_output_frame.src = generate_url.value + "?new_user_text=" + new_user_text.value;
        }
    </script>
</div>
