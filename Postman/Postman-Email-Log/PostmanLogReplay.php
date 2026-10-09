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

    private static function members( $id, $legacy ) {
        global $wpdb;
        $modern = self::hasModernTables();
        $source = $legacy ? (int) $id : 0;
        $ids = $legacy ? array() : array( (int) $id );
        if ( $modern ) {
            if ( ! $legacy ) {
                $source_value = $wpdb->get_var( PostmanUtils::prepareQueryWithTable( 'SELECT meta_value FROM %i WHERE log_id=%d AND meta_key=%s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'post_smtp_logmeta', array( $id, self::SOURCE_KEY ) ) );
                if ( $wpdb->last_error || ( $source_value !== null && ( ! ctype_digit( (string) $source_value ) || (int) $source_value < 1 ) ) ) { throw new RuntimeException( self::holdMessage() ); }
                $source = (int) $source_value;
            }
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
        if ( $member['legacy'] ) {
            return array( 'transport_uri'=>get_post_meta( $member['id'], 'transport_uri', true ), 'session_transcript'=>get_post_meta( $member['id'], 'session_transcript', true ), 'success'=>get_post_meta( $member['id'], 'success', true ) );
        }
        global $wpdb;
        $log = $wpdb->get_row( PostmanUtils::prepareQueryWithTable( 'SELECT transport_uri, session_transcript, success FROM %i WHERE id=%d', $wpdb->prefix . 'post_smtp_logs', array( $member['id'] ) ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( self::holdMessage() ); }
        return $log;
    }

    private static function write( $member, $transcript ) {
        if ( $member['legacy'] ) {
            update_post_meta( $member['id'], 'session_transcript', wp_slash( $transcript ) );
            return get_post_meta( $member['id'], 'session_transcript', true ) === $transcript;
        }
        global $wpdb;
        return $wpdb->update( $wpdb->prefix . 'post_smtp_logs', array( 'session_transcript'=>$transcript ), array( 'id'=>$member['id'] ) ) !== false;
    }

    public static function begin( $id, $legacy = false ) {
        $members = self::members( $id, $legacy );
        foreach ( $members as $member ) {
            $log = self::read( $member );
            if ( ! $log || ! PostmanMailChannelsTransport::canResendLog( $log['transport_uri'], $log['session_transcript'], $log['success'] ) ) {
                throw new RuntimeException( self::holdMessage() );
            }
        }
        foreach ( $members as $member ) {
            $log = self::read( $member );
            if ( ! self::write( $member, $log['session_transcript'] . "\n" . self::PENDING ) ) {
                // Partial reservation remains held; no send is started on a write failure.
                throw new RuntimeException( self::holdMessage() );
            }
        }
        return $members;
    }

    public static function finish( $members, $transcript ) {
        $unknown = strpos( (string) $transcript, PostmanMailChannelsTransport::OUTCOME_UNKNOWN ) !== false || strpos( (string) $transcript, 'SMTP delivery outcome unknown.' ) !== false;
        foreach ( $members as $member ) {
            $log = self::read( $member );
            if ( ! $log ) { continue; }
            $saved = str_replace( "\n" . self::PENDING, '', (string) $log['session_transcript'] );
            if ( $unknown && strpos( $saved, self::UNKNOWN ) === false ) { $saved .= "\n" . self::UNKNOWN; }
            // A failed completion write leaves PENDING persisted and still blocks replay.
            self::write( $member, $saved );
        }
    }

    public static function holdMessage() {
        return __( 'A replay of this log or its migration source has a pending or unknown outcome. Verify provider or server acceptance before creating a new send.', 'post-smtp' );
    }
}
