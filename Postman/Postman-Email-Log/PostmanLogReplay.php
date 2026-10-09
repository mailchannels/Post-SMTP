<?php
defined( 'ABSPATH' ) || exit;

/** Reserve exact log sources before a replay; never infer identity from message content. */
class PostmanLogReplay {
    const SOURCE_KEY = 'post_smtp_legacy_log_id';
    const PENDING = 'Post SMTP replay outcome pending.';
    const UNKNOWN = 'Post SMTP replay outcome unknown.';

    private static function hasModernTables() {
        global $wpdb;
        $table = $wpdb->prefix . 'post_smtp_logmeta';
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( self::holdMessage() ); }
        return $found === $table;
    }

    public static function saveSource( $id, $legacy_id ) {
        global $wpdb;
        return $wpdb->insert( $wpdb->prefix . 'post_smtp_logmeta', array( 'log_id'=>$id, 'meta_key'=>self::SOURCE_KEY, 'meta_value'=>(string) $legacy_id ) ) !== false;
    }

    private static function source( $id, $legacy ) {
        if ( $legacy ) { return (int) $id; }
        if ( ! self::hasModernTables() ) { throw new RuntimeException( self::holdMessage() ); }
        global $wpdb;
        $value = $wpdb->get_var( PostmanUtils::prepareQueryWithTable( 'SELECT meta_value FROM %i WHERE log_id=%d AND meta_key=%s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'post_smtp_logmeta', array( $id, self::SOURCE_KEY ) ) );
        if ( $wpdb->last_error || ( $value !== null && ( ! ctype_digit( (string) $value ) || (int) $value < 1 ) ) ) { throw new RuntimeException( self::holdMessage() ); }
        return (int) $value;
    }

    public static function lockName( $id, $legacy = false ) {
        global $wpdb;
        $source = self::source( $id, $legacy );
        $identity = $source ? 'legacy:' . $source : 'modern:' . (int) $id;
        return 'post-smtp-replay:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $identity ), 0, 40 );
    }

    private static function ownsLock( $name ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $name ) ) === '1';
    }

    private static function releaseLock( $name ) {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    private static function members( $id, $legacy ) {
        global $wpdb;
        $modern = self::hasModernTables();
        $source = self::source( $id, $legacy );
        $ids = $legacy ? array() : array( (int) $id );
        if ( $modern ) {
            if ( $source ) {
                $related = $wpdb->get_col( PostmanUtils::prepareQueryWithTable( 'SELECT log_id FROM %i WHERE meta_key=%s AND meta_value=%s', $wpdb->prefix . 'post_smtp_logmeta', array( self::SOURCE_KEY, (string) $source ) ) );
                if ( $wpdb->last_error ) { throw new RuntimeException( self::holdMessage() ); }
                $ids = array_unique( array_merge( $ids, array_map( 'intval', $related ) ) );
            }
        }
        $members = array();
        if ( $source && get_post_type( $source ) === 'postman_sent_mail' ) {
            $members[] = array( 'id'=>$source, 'legacy'=>true );
        }
        foreach ( $ids as $modern_id ) {
            if ( $modern && self::read( array( 'id'=>$modern_id, 'legacy'=>false ) ) ) {
                $members[] = array( 'id'=>$modern_id, 'legacy'=>false );
            }
        }
        if ( empty( $members ) ) { throw new RuntimeException( self::holdMessage() ); }
        return $members;
    }

    private static function read( $member ) {
        global $wpdb;
        if ( $member['legacy'] ) {
            // Another request may have changed metadata after the controller read it.
            wp_cache_delete( $member['id'], 'post_meta' );
            $log = array();
            foreach ( array( 'transport_uri', 'session_transcript', 'success' ) as $field ) {
                $log[$field] = get_post_meta( $member['id'], $field, true );
                if ( $wpdb->last_error ) { throw new RuntimeException( self::holdMessage() ); }
            }
            return $log;
        }
        global $wpdb;
        $log = $wpdb->get_row( PostmanUtils::prepareQueryWithTable( 'SELECT transport_uri, session_transcript, success FROM %i WHERE id=%d', $wpdb->prefix . 'post_smtp_logs', array( $member['id'] ) ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( self::holdMessage() ); }
        return $log;
    }

    private static function write( $member, $transcript, $lock ) {
        global $wpdb;
        // Check ownership in the mutation itself, including after a wpdb reconnect.
        if ( $member['legacy'] ) {
            $query = PostmanUtils::prepareQueryWithTable( 'UPDATE %i SET meta_value=%s WHERE post_id=%d AND meta_key=%s AND IS_USED_LOCK(%s)=CONNECTION_ID()', $wpdb->postmeta, array( $transcript, $member['id'], 'session_transcript', $lock ) );
        } else {
            $query = PostmanUtils::prepareQueryWithTable( 'UPDATE %i SET session_transcript=%s WHERE id=%d AND IS_USED_LOCK(%s)=CONNECTION_ID()', $wpdb->prefix . 'post_smtp_logs', array( $transcript, $member['id'], $lock ) );
        }
        if ( $wpdb->query( $query ) === false || ! self::ownsLock( $lock ) ) { return false; }
        $log = self::read( $member );
        return $log && $log['session_transcript'] === $transcript;
    }

    public static function begin( $id, $legacy = false ) {
        global $wpdb;
        $name = self::lockName( $id, $legacy );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) !== '1' ) {
            throw new RuntimeException( self::holdMessage() );
        }
        $reserved = false;
        try {
            // Re-resolve lineage under the lock; migration changes must not switch keys.
            if ( self::lockName( $id, $legacy ) !== $name ) { throw new RuntimeException( self::holdMessage() ); }
            $members = self::members( $id, $legacy );
            foreach ( $members as $member ) {
                $log = self::read( $member );
                if ( ! $log || ! PostmanMailChannelsTransport::canResendLog( $log['transport_uri'], $log['session_transcript'], $log['success'] ) ) {
                    throw new RuntimeException( self::holdMessage() );
                }
            }
            foreach ( $members as $member ) {
                $log = self::read( $member );
                if ( ! self::write( $member, $log['session_transcript'] . "\n" . self::PENDING, $name ) ) {
                    // Partial reservation remains held; no send starts on a write failure.
                    throw new RuntimeException( self::holdMessage() );
                }
            }
            // wpdb can reconnect; a replacement session must not send under a lost lock.
            if ( ! self::ownsLock( $name ) ) { throw new RuntimeException( self::holdMessage() ); }
            $reserved = true;
            return array( 'members'=>$members, 'lock'=>$name );
        } finally {
            if ( ! $reserved ) { self::releaseLock( $name ); }
        }
    }

    public static function finish( $reservation, $transcript ) {
        try {
            // Lost session ownership must leave the durable pending marker intact.
            if ( ! self::ownsLock( $reservation['lock'] ) ) { return; }
            $unknown = strpos( (string) $transcript, PostmanMailChannelsTransport::OUTCOME_UNKNOWN ) !== false || strpos( (string) $transcript, 'SMTP delivery outcome unknown.' ) !== false;
            foreach ( $reservation['members'] as $member ) {
                $log = self::read( $member );
                if ( ! $log ) { continue; }
                $saved = str_replace( "\n" . self::PENDING, '', (string) $log['session_transcript'] );
                if ( $unknown && strpos( $saved, self::UNKNOWN ) === false ) { $saved .= "\n" . self::UNKNOWN; }
                // A failed completion write leaves PENDING persisted and blocks replay.
                self::write( $member, $saved, $reservation['lock'] );
            }
        } finally {
            self::releaseLock( $reservation['lock'] );
        }
    }

    public static function holdMessage() {
        return __( 'A replay of this log or its migration source has a pending or unknown outcome. Verify provider or server acceptance before creating a new send.', 'post-smtp' );
    }
}
