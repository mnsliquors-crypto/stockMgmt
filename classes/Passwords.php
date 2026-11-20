<?php

function verify_user_password($password, $hash) {
    if (!is_string($hash) || $hash === '') return false;
    if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
        return hash_equals(strtolower($hash), md5($password));
    }
    return password_verify($password, $hash);
}
