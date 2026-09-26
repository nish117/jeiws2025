<?php
defined('JEIWS_CONFIG') or die('Direct access denied.');

// Google reCAPTCHA v2 ("I'm not a robot" checkbox) — register the site at
// https://www.google.com/recaptcha/admin to get your own Site Key + Secret
// Key, then replace the two values below.
//
// The pair currently set is Google's official published TEST key pair
// (documented at https://developers.google.com/recaptcha/docs/faq) — it
// always passes verification on any domain, including localhost, so the
// form works end-to-end during development, but it shows a visible
// "This is a test key" notice and MUST be swapped for real keys before
// going live in production.
return [
    'siteKey' => '6LdzbcotAAAAAKOWS69WG8d8ecYqrMEJvexe3tfI',
    'secretKey' => '6LdzbcotAAAAACMuWmTPbAUMuwT0LZhvfZIiZa18',
];
