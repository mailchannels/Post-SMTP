<?php
defined( 'ABSPATH' ) || exit;

/** Immutable attachment content belongs to the same private log and retention policy. */
class PostmanAttachmentReplay {
    const KEY = 'post_smtp_attachment_snapshot';
    const REQUIRED = 'Post SMTP attachment snapshot required.';

    public static function capture( PostmanMessage $message ) {
        if ( empty( $message->getAttachments() ) ) { return null; }
        try {
            $parts = $message->getAttachmentParts();
            foreach ( $parts as &$part ) {
                $part['sha256'] = hash( 'sha256', $part['data'] );
                $part['content'] = base64_encode( $part['data'] );
                unset( $part['data'] );
            }
            unset( $part );
            return json_encode( array( 'version'=>1, 'parts'=>$parts ) );
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

    public static function restore( $id, $transcript, $legacy = false ) {
        if ( $legacy ) { $snapshot = get_post_meta( $id, self::KEY, true ); }
        else {
            global $wpdb;
            $snapshot = $wpdb->get_var( PostmanUtils::prepareQueryWithTable( 'SELECT meta_value FROM %i WHERE log_id=%d AND meta_key=%s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'post_smtp_logmeta', array( $id, self::KEY ) ) );
        }
        if ( $snapshot === null || $snapshot === '' ) {
            if ( strpos( (string) $transcript, self::REQUIRED ) !== false ) { throw new RuntimeException( self::holdMessage() ); }
            return array(); // Older logs have no reliable attachment history.
        }
        $transport = PostmanTransportRegistry::getInstance()->getCurrentTransport();
        if ( ! ( $transport instanceof PostmanMailChannelsTransport ) && ! ( $transport instanceof PostmanSmtpModuleTransport ) ) {
            throw new RuntimeException( __( 'Attachment replay is unavailable for the selected mailer. Create a new message with the required attachments.', 'post-smtp' ) );
        }
        $manifest = json_decode( $snapshot, true );
        if ( ! is_array( $manifest ) || ! isset( $manifest['version'], $manifest['parts'] ) || $manifest['version'] !== 1 || ! is_array( $manifest['parts'] ) || ! empty( $manifest['unavailable'] ) ) { throw new RuntimeException( self::holdMessage() ); }
        $attachments = array();
        foreach ( $manifest['parts'] as $part ) {
            foreach ( array( 'content', 'sha256', 'filename', 'type', 'disposition', 'encoding', 'cid' ) as $field ) {
                if ( ! isset( $part[$field] ) || ! is_string( $part[$field] ) ) { throw new RuntimeException( self::holdMessage() ); }
            }
            $data = base64_decode( $part['content'], true );
            if ( $data === false || ! hash_equals( $part['sha256'], hash( 'sha256', $data ) ) || preg_match( '/[\r\n]/', $part['filename'] . $part['type'] . $part['cid'] ) || ! in_array( $part['disposition'], array( 'inline', 'attachment' ), true ) || ! in_array( $part['encoding'], array( 'base64', 'quoted-printable', '7bit', '8bit', 'binary' ), true ) ) { throw new RuntimeException( self::holdMessage() ); }
            // Existing embedded tuple, with string data instead of a local pathname.
            $attachments[] = array( $data, $part['filename'], $part['filename'], $part['encoding'], $part['type'], true, $part['disposition'], $part['cid'] );
        }
        return $attachments;
    }

    public static function holdMessage() {
        return __( 'The original attachments could not be restored. This message was not resent. Create a new message with the required attachments.', 'post-smtp' );
    }
}
