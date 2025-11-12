<?php
declare(strict_types=1);

namespace Preauth;

class Env {
    /**
     * @return string returns title of this system
     */
    public function getTitle(): string {
        return getenv('PREAUTH_TITLE') ?: 'Pre-Authentication System';
    }

    /**
     * @return string returns background-color of this system
     */
    public function getColor(): string {
        return getenv('PREAUTH_BACKGROUND') ?: '#029386'; // teal
    }

    /**
     * @return string returns text-color of this system
     */
    public function getTextColor(): string {
        return getenv('PREAUTH_FOREGROUND') ?: '#ffffff'; // white
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
        return getenv('PREAUTH_TOKEN_NAME') ?: 'Authentication Token';
    }

    /**
     * @return string returns the name of the Submit button
     */
    public function getSubmitName(): string {
        return getenv('PREAUTH_SUBMIT_NAME') ?: 'Submit';
    }
}

