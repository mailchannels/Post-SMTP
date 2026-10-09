<?php
defined( 'ABSPATH' ) || exit;

/** Immutable attachment content belongs to the same private log and retention policy. */
class PostmanAttachmentReplay {
    const KEY = 'post_smtp_attachment_snapshot';
    const REQUIRED = 'Post SMTP attachment snapshot required.';
    const BODIES_REQUIRED = 'Post SMTP alternative body snapshot required.';

    public static function capture( PostmanMessage $message ) {
        $has_bodies = $message->getBodyTextPart() !== null && $message->getBodyTextPart() !== '' && $message->getBodyHtmlPart() !== null && $message->getBodyHtmlPart() !== '';
        if ( empty( $message->getAttachments() ) && ! $has_bodies ) { return null; }
        try {
            $parts = $message->getAttachmentParts();
            foreach ( $parts as &$part ) {
                $part['sha256'] = hash( 'sha256', $part['data'] );
                $part['content'] = base64_encode( $part['data'] );
                unset( $part['data'] );
            }
            unset( $part );
            $manifest = array( 'version'=>empty( $parts ) ? 1 : 2, 'parts'=>$parts );
            if ( ! empty( $parts ) ) { $manifest['attachment_identity'] = 'ordinal-v1'; }
            if ( $has_bodies ) {
                $text = $message->getBodyTextPart();
                $html = $message->getBodyHtmlPart();
                $manifest['bodies'] = array( 'charset'=>$message->getCharset(), 'text'=>base64_encode( $text ), 'html'=>base64_encode( $html ), 'text_sha256'=>hash( 'sha256', $text ), 'html_sha256'=>hash( 'sha256', $html ) );
            }
            return json_encode( $manifest );
        } catch ( Exception $error ) {
            // Original attachments were unreadable: do not offer a partial resend.
            return json_encode( array( 'version'=>1, 'unavailable'=>true ) );
        }
    }

    public static function save( $id, $snapshot, $legacy = false ) {
        if ( $snapshot === null ) { return true; }
        if ( $legacy ) {
            update_post_meta( $id, self::KEY, wp_slash( $snapshot ) );
            return get_post_meta( $id, self::KEY, true ) === $snapshot;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'post_smtp_logmeta';
        $wpdb->delete( $table, array( 'log_id'=>$id, 'meta_key'=>self::KEY ) );
        return $wpdb->insert( $table, array( 'log_id'=>$id, 'meta_key'=>self::KEY, 'meta_value'=>$snapshot ) ) !== false;
    }

    public static function restore( $id, $transcript, $legacy = false, &$bodies = null ) {
        $bodies = null;
        if ( $legacy ) { $snapshot = get_post_meta( $id, self::KEY, true ); }
        else {
            global $wpdb;
            $snapshot = $wpdb->get_var( PostmanUtils::prepareQueryWithTable( 'SELECT meta_value FROM %i WHERE log_id=%d AND meta_key=%s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'post_smtp_logmeta', array( $id, self::KEY ) ) );
        }
        if ( $snapshot === null || $snapshot === '' ) {
            if ( strpos( (string) $transcript, self::REQUIRED ) !== false || strpos( (string) $transcript, self::BODIES_REQUIRED ) !== false ) { throw new RuntimeException( self::holdMessage() ); }
            return array(); // Older logs have no reliable attachment history.
        }
        $transport = PostmanTransportRegistry::getInstance()->getCurrentTransport();
        if ( ! ( $transport instanceof PostmanMailChannelsTransport ) && ! ( $transport instanceof PostmanSmtpModuleTransport ) ) {
            throw new RuntimeException( __( 'Attachment replay is unavailable for the selected mailer. Create a new message with the required attachments.', 'post-smtp' ) );
        }
        $manifest = json_decode( $snapshot, true );
        if ( ! is_array( $manifest ) || ! isset( $manifest['version'], $manifest['parts'] ) || ! in_array( $manifest['version'], array( 1, 2 ), true ) || ! is_array( $manifest['parts'] ) || ! empty( $manifest['unavailable'] ) ) { throw new RuntimeException( self::holdMessage() ); }
        $identity = $manifest['version'] === 2;
        if ( $identity && ( ! isset( $manifest['attachment_identity'] ) || $manifest['attachment_identity'] !== 'ordinal-v1' ) ) { throw new RuntimeException( self::holdMessage() ); }
        if ( isset( $manifest['bodies'] ) ) {
            $saved = $manifest['bodies'];
            foreach ( array( 'charset', 'text', 'html', 'text_sha256', 'html_sha256' ) as $field ) {
                if ( ! isset( $saved[$field] ) || ! is_string( $saved[$field] ) ) { throw new RuntimeException( self::holdMessage() ); }
            }
            if ( ! preg_match( '/^[a-zA-Z0-9._-]+$/D', $saved['charset'] ) ) { throw new RuntimeException( self::holdMessage() ); }
            $bodies = array( 'charset'=>$saved['charset'] );
            foreach ( array( 'text', 'html' ) as $field ) {
                $data = base64_decode( $saved[$field], true );
                if ( $data === false || ! hash_equals( $saved[$field . '_sha256'], hash( 'sha256', $data ) ) ) { throw new RuntimeException( self::holdMessage() ); }
                $bodies[$field] = $data;
            }
        } elseif ( strpos( (string) $transcript, self::BODIES_REQUIRED ) !== false ) { throw new RuntimeException( self::holdMessage() ); }
        $attachments = array();
        foreach ( $manifest['parts'] as $part ) {
            foreach ( array( 'content', 'sha256', 'filename', 'type', 'disposition', 'encoding', 'cid' ) as $field ) {
                if ( ! isset( $part[$field] ) || ! is_string( $part[$field] ) ) { throw new RuntimeException( self::holdMessage() ); }
            }
            $data = base64_decode( $part['content'], true );
            if ( $data === false || ! hash_equals( $part['sha256'], hash( 'sha256', $data ) ) || preg_match( '/[\r\n]/', $part['filename'] . $part['type'] . $part['cid'] ) || ! in_array( $part['disposition'], array( 'inline', 'attachment' ), true ) || ! in_array( $part['encoding'], array( 'base64', 'quoted-printable', '7bit', '8bit', 'binary' ), true ) ) { throw new RuntimeException( self::holdMessage() ); }
            // Existing embedded tuple, with string data instead of a local pathname.
            $attachments[] = array( $data, $identity ? 'post-smtp-snapshot:' . count( $attachments ) : $part['filename'], $part['filename'], $part['encoding'], $part['type'], true, $part['disposition'], $part['cid'] );
        }
        return $attachments;
    }

    /** Keep internal tuples out of core wp_mail's file-path attachment API. */
    public static function send( $to, $subject, $message, $headers, $attachments, $bodies = null ) {
        $function = new ReflectionFunction( 'wp_mail' );
        if ( basename( $function->getFileName() ) === 'PostmanWpMailBinder.php' ) {
            return wp_mail( $to, $subject, $message, $headers, $attachments, array(), $bodies );
        }
        // Core wp_mail shares one mutable global mailer. A nested call would
        // overwrite outer recipients and inherit this private replay snapshot.
        $depth = self::wpMailDepth() + 1;
        $preempt = static function ( $result ) use ( $depth ) {
            return PostmanAttachmentReplay::wpMailDepth() > $depth ? false : $result;
        };
        // Core before 5.7 has no pre_wp_mail short circuit. Throw before it
        // touches the shared object rather than silently sending replay bytes.
        $legacy_guard = static function ( $atts ) use ( $depth ) {
            if ( PostmanAttachmentReplay::wpMailDepth() > $depth ) {
                throw new RuntimeException( 'Post SMTP cannot send nested mail during an immutable log replay.' );
            }
            return $atts;
        };
        $legacy_core = version_compare( get_bloginfo( 'version' ), '5.7', '<' );
        $paths = array();
        $tuples = array();
        foreach ( (array) $attachments as $attachment ) {
            if ( is_array( $attachment ) ) { $tuples[] = $attachment; }
            else { $paths[] = $attachment; }
        }
        $poisoned = false;
        $replay_mailer = null;
        $hook = static function ( $mailer ) use ( $tuples, $bodies, $depth, &$poisoned, &$replay_mailer ) {
            // A later filter must not override nested preemption and allow a
            // partial outer send after the nested call corrupts shared state.
            if ( $poisoned || PostmanAttachmentReplay::wpMailDepth() !== $depth ) {
                $poisoned = true;
                throw new RuntimeException( 'Post SMTP cannot send nested mail during an immutable log replay.' );
            }
            $replay_mailer = $mailer;
            // Restore only this snapshot, regardless of earlier hook body changes.
            $mailer->AltBody = '';
            if ( $bodies !== null ) {
                $mailer->isHTML( true );
                $mailer->Body = $bodies['html'];
                $mailer->AltBody = $bodies['text'];
                $mailer->CharSet = $bodies['charset'];
            }
            foreach ( $tuples as $part ) {
                if ( count( $part ) < 8 ) { throw new RuntimeException( PostmanAttachmentReplay::holdMessage() ); }
                if ( strpos( $part[1], 'post-smtp-snapshot:' ) === 0 ) {
                    if ( ! ( $mailer instanceof PostsmtpMailer ) || ! $mailer->addSnapshotAttachment( $part ) ) {
                        throw new RuntimeException( PostmanAttachmentReplay::holdMessage() );
                    }
                    continue;
                }
                if ( $part[6] === 'inline' ) {
                    $method = $part[5] ? 'addStringEmbeddedImage' : 'addEmbeddedImage';
                    $ok = $mailer->$method( $part[0], $part[7], $part[2], $part[3], $part[4], $part[6] );
                } else {
                    $method = $part[5] ? 'addStringAttachment' : 'addAttachment';
                    $ok = $mailer->$method( $part[0], $part[2], $part[3], $part[4], $part[6] );
                }
                if ( ! $ok ) { throw new RuntimeException( PostmanAttachmentReplay::holdMessage() ); }
            }
        };
        if ( $legacy_core ) { add_filter( 'wp_mail', $legacy_guard, PHP_INT_MIN ); }
        else { add_filter( 'pre_wp_mail', $preempt, PHP_INT_MAX ); }
        add_action( 'phpmailer_init', $hook, PHP_INT_MAX );
        try { return wp_mail( $to, $subject, $message, $headers, $paths ); }
        finally {
            // Clear the temporary alternative body when the replay scope ends.
            if ( $replay_mailer !== null ) { $replay_mailer->AltBody = ''; }
            remove_action( 'phpmailer_init', $hook, PHP_INT_MAX );
            if ( $legacy_core ) { remove_filter( 'wp_mail', $legacy_guard, PHP_INT_MIN ); }
            else { remove_filter( 'pre_wp_mail', $preempt, PHP_INT_MAX ); }
        }
    }

    private static function wpMailDepth() {
        $depth = 0;
        // Omit arguments: snapshots and message content must not enter traces.
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
            if ( $frame['function'] === 'wp_mail' && ! isset( $frame['class'] ) ) { ++$depth; }
        }
        return $depth;
    }

    public static function holdMessage() {
        return __( 'The original attachments could not be restored. This message was not resent. Create a new message with the required attachments.', 'post-smtp' );
    }
}
