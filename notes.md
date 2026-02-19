changes in store...

work to add passkeys to preauth

make it optional to configure a "auth subdomain" field,
when given, does the whole redirect to "auth.devgnome.com" and use "devgnome.com" root cookie for session cookie

add ability to generate single use backup-codes... store them hashed, and keep track of which ones have been used
stretch goal, send notification when a burner backup code is used... email/slack/ntfy..

removing the static-secret system
removing the lookup-totp system




