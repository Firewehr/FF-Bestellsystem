/**
 * Passkey/WebAuthn Browser-Helfer: ArrayBuffer<->Base64url + create()/get() Wrapper.
 * Wird sowohl auf der Login-Seite als auch im Admin (Passkey-Verwaltung) eingebunden.
 */
(function (global) {
    function b64urlToBuffer(b64url) {
        var pad = b64url.length % 4 === 0 ? '' : '='.repeat(4 - (b64url.length % 4));
        var base64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(base64);
        var buf = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
        return buf.buffer;
    }

    function bufferToB64url(buf) {
        var bytes = new Uint8Array(buf);
        var str = '';
        for (var i = 0; i < bytes.byteLength; i++) str += String.fromCharCode(bytes[i]);
        return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function supported() {
        return !!(global.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
    }

    function createPasskey(options) {
        var publicKey = Object.assign({}, options);
        publicKey.challenge = b64urlToBuffer(options.challenge);
        publicKey.user = Object.assign({}, options.user, { id: b64urlToBuffer(options.user.id) });
        if (Array.isArray(options.excludeCredentials)) {
            publicKey.excludeCredentials = options.excludeCredentials.map(function (c) {
                return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
            });
        }
        return navigator.credentials.create({ publicKey: publicKey }).then(function (cred) {
            return {
                id: cred.id,
                rawId: bufferToB64url(cred.rawId),
                type: cred.type,
                response: {
                    clientDataJSON: bufferToB64url(cred.response.clientDataJSON),
                    attestationObject: bufferToB64url(cred.response.attestationObject),
                    transports: (cred.response.getTransports ? cred.response.getTransports() : [])
                }
            };
        });
    }

    function getPasskey(options) {
        var publicKey = Object.assign({}, options);
        publicKey.challenge = b64urlToBuffer(options.challenge);
        if (Array.isArray(options.allowCredentials) && options.allowCredentials.length) {
            publicKey.allowCredentials = options.allowCredentials.map(function (c) {
                return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
            });
        } else {
            delete publicKey.allowCredentials;
        }
        return navigator.credentials.get({ publicKey: publicKey }).then(function (assertion) {
            return {
                id: assertion.id,
                rawId: bufferToB64url(assertion.rawId),
                type: assertion.type,
                response: {
                    clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToB64url(assertion.response.authenticatorData),
                    signature: bufferToB64url(assertion.response.signature),
                    userHandle: assertion.response.userHandle ? bufferToB64url(assertion.response.userHandle) : null
                }
            };
        });
    }

    global.FfWebAuthn = {
        supported: supported,
        createPasskey: createPasskey,
        getPasskey: getPasskey
    };
})(window);
