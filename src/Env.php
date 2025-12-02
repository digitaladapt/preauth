<?php
declare(strict_types=1);

namespace Preauth;

class Env {
    /**
     * @return string returns title of this system
     */
    public function getTitle(): string {
        return getenv('PREAUTH_TITLE')
            ?: 'Pre-Authentication System';
    }

    /**
     * @return string returns background-color of this system
     */
    public function getColor(): string {
        // defaults to teal
        return getenv('PREAUTH_BACKGROUND') ?: '#029386';
    }

    /**
     * @return string returns text-color of this system
     */
    public function getTextColor(): string {
        // defaults to white
        return getenv('PREAUTH_FOREGROUND') ?: '#ffffff';
    }

    /**
     * @return string returns the name of the ID field
     */
    public function getIdName(): string {
        return getenv('PREAUTH_ID_NAME') ?: 'Session ID';
    }

    /**
     * @return string returns the name of the Token field
     */
    public function getTokenName(): string {
        return getenv('PREAUTH_TOKEN_NAME')
            ?: 'Authentication Token';
    }

    /**
     * @return string returns the name of the Submit button
     */
    public function getSubmitName(): string {
        return getenv('PREAUTH_SUBMIT_NAME') ?: 'Submit';
    }

    /**
     * @return string returns the denied http status code
     */
    public function getDeniedCode(): string {
        return getenv('PREAUTH_DENIED_CODE') ?: '418';
    }

    /**
     * @return string returns the denied response title
     */
    public function getDeniedTitle(): string {
        return getenv('PREAUTH_DENIED_TITLE')
            ?: "I'm a teapot";
    }

    /**
     * @return string returns the denied response message
     */
    public function getDeniedMessage(): string {
        return getenv('PREAUTH_DENIED_MESSAGE')
            ?: 'I refuse to brew coffee.';
    }
}

