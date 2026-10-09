<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( version_compare( get_bloginfo( 'version' ), '5.5-alpha', '<' ) ) {
	if ( ! class_exists( '\PHPMailer', false ) && ! class_exists( 'PHPMailer', false ) ) {
		require_once ABSPATH . WPINC . '/class-phpmailer.php';
	}

} else {
	if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	}

	if ( ! class_exists( '\PHPMailer\PHPMailer\Exception', false ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	}

	if ( ! class_exists( '\PHPMailer\PHPMailer\SMTP', false ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	}

	// Guard class aliases to prevent redeclaration errors
	if ( ! class_exists( 'PHPMailer', false ) ) {
		class_alias( PHPMailer\PHPMailer\PHPMailer::class, 'PHPMailer' );
	}
	if ( ! class_exists( 'SMTP', false ) ) {
		class_alias( PHPMailer\PHPMailer\SMTP::class, 'SMTP' );
	}
	if ( ! class_exists( 'phpmailerException', false ) ) {
		class_alias( PHPMailer\PHPMailer\Exception::class, 'phpmailerException' );
	}
}


// Observe DATA before PHPMailer runs RSET/QUIT or per-recipient callbacks.
// A recipients_failed exception may follow successful delivery to other RCPTs.
if ( ! class_exists( 'PostmanNativeSmtp', false ) && class_exists( 'SMTP', false ) ) {
    class PostmanNativeSmtp extends SMTP {
        private $postmanDataOutcome = '';

        public function resetPostmanDataOutcome() {
            $this->postmanDataOutcome = '';
        }

        public function getPostmanDataOutcome() {
            return $this->postmanDataOutcome;
        }

        public function data( $msg_data ) {
            $accepted = parent::data( $msg_data );
            if ( $accepted ) {
                $this->postmanDataOutcome = 'accepted';
            } else {
                $error = $this->getError();
                $code = isset( $error['smtp_code'] ) ? (int) $error['smtp_code'] : 0;
                if ( isset( $error['error'] ) && $error['error'] === 'DATA END command failed' && ( $code < 400 || $code >= 600 ) ) {
                    $this->postmanDataOutcome = 'unknown';
                }
            }
            return $accepted;
        }
    }
}


add_action( 'plugins_loaded', function() {
	global $phpmailer;

	// Only instantiate if class exists and $phpmailer isn't already a PostsmtpMailer instance
	if ( class_exists( 'PostsmtpMailer', false ) && ( ! isset( $phpmailer ) || ! ( $phpmailer instanceof PostsmtpMailer ) ) ) {
		$phpmailer = new PostsmtpMailer( true );
	}
} );

// Guard the class declaration - only declare if PHPMailer exists and PostsmtpMailer doesn't
if ( ! class_exists( 'PostsmtpMailer', false ) && class_exists( 'PHPMailer', false ) ) {
	class PostsmtpMailer extends PHPMailer {

		private $mail_args = array();

		private $options;

		private $error;

		private $transcript = '';
        private $smtpDataEndUnknown = false;
        private $smtpAttachmentBytes = null;
        private $smtpPhaseStarted = false;
        private $smtpCallbackPhase = false;
        private $smtpCallbackSeen = 0;
        private $smtpCallbackExpected = 0;
        private $smtpCallbackAccepted = false;
        private $smtpCallbackOutcome = '';


		public function __construct( $exceptions = null ) {
			parent::__construct( $exceptions );

			$this->set_vars();
			$this->hooks();
		}

        public function getSMTPInstance() {
            // Preserve caller-supplied SMTP subclasses and all configuration.
            if ( ! is_object( $this->smtp ) ) {
                $this->smtp = new PostmanNativeSmtp();
            }
            return $this->smtp;
        }

		public function set_vars() {
			$this->options = PostmanOptions::getInstance();
			$this->Debugoutput = function( $str, $level ) {
				$this->transcript .= $str;
                // Inspect the structured error at DATA END, before cleanup or
                // another SMTP command can replace it. Pinned SMTP implementations
                // emit this phase through their error debug callback.
                if ( strpos( $str, 'SMTP ERROR: DATA END command failed:' ) === 0 ) {
                    $error = $this->getSMTPInstance()->getError();
                    $code = isset( $error['smtp_code'] ) ? (int) $error['smtp_code'] : 0;
                    $this->smtpDataEndUnknown = $code < 400 || $code >= 600;
                }
			};
		}

		public function hooks() {
			add_filter( 'wp_mail', array( $this, 'get_mail_args' ) );
			if ( $this->options->getTransportType() == 'smtp' ) {
				add_action( 'phpmailer_init', array( $this, 'phpmailer_smtp_init' ), 999 );
			}
		}

		public function get_mail_args( $atts ) {
            // Start each mapped wp_mail call with fresh alternative content.
            // Older core versions retain AltBody on the shared PHPMailer.
            if ( in_array( $this->options->getTransportType(), array( 'mailchannels_api', 'smtp' ), true ) ) {
                $this->AltBody = '';
            }
			$this->mail_args = array();
			$this->mail_args[] = @$atts['to'];
			$this->mail_args[] = @$atts['subject'];
			$this->mail_args[] = @$atts['message'];
			$this->mail_args[] = @$atts['headers'];
			$this->mail_args[] = @$atts['attachments'];

			return $atts;
		}

		/**
		 * @param PHPMailer $mail
		 */
		public function phpmailer_smtp_init( $mail ) {
			$mail->SMTPDebug = 3;
			$mail->isSMTP();
			$mail->Host = $this->options->getHostname();
			$mail->Hostname = $this->options->getHostname();
			$mail->Port = $this->options->getPort();

			if ( $this->options->getEncryptionType() !== 'none' ) {
				$mail->SMTPSecure = $this->options->getEncryptionType();
				// When using SSL (SMTPS) on port 465, disable AutoTLS so PHPMailer doesn't try STARTTLS on top of the SSL connection (matches WP Mail SMTP behavior; some servers like mail.nic.ru fail otherwise).
				if ( $this->options->getEncryptionType() === PostmanOptions::SECURITY_TYPE_SMTPS ) {
					$mail->SMTPAutoTLS = false;
				}
			}

			if ( $this->options->getAuthenticationType() !== 'none' ) {
				$mail->SMTPAuth   = true;
				$mail->Username   = $this->options->getUsername();
				$mail->Password   = $this->options->getPassword();
			}

			if ( $this->options->isPluginSenderEmailEnforced() ) {
				$mail->setFrom( $this->options->getMessageSenderEmail(), $this->options->getMessageSenderName() );
			}
		}

		public function send() {
			require_once dirname( __DIR__ ) . '/PostmanWpMail.php';

			// create a PostmanWpMail instance
			$postmanWpMail = new PostmanWpMail();
			$postmanWpMail->init();

            if ( $this->options->getTransportType() === 'mailchannels_api' ) {
                $this->error = null;
                $this->transcript = '';
                try {
                    if ( $this->sign_key_file !== '' ) { throw new phpmailerException( 'MailChannels cannot preserve PHPMailer S/MIME signing.' ); }
                    return $postmanWpMail->sendPhpMailer( $this );
                }
                catch ( Exception $error ) {
                    $this->error = $error;
                    $this->setError( $error->getMessage() );
                    // Validation can fail before the helper registers its result.
                    if ( ! has_filter( 'postman_wp_mail_result', array( $postmanWpMail, 'postman_wp_mail_result' ) ) ) {
                        PostmanWpMail::registerCompletedResult( $this );
                    }
                    if ( $this->exceptions ) { throw $error; }
                    return false;
                }
            }

			list( $to, $subject, $body, $headers, $attachments ) = array_pad( $this->mail_args, 5, null );

            if ( $this->options->getTransportType() === 'smtp' ) {
                $this->error = null;
                $this->transcript = '';
                $this->smtpDataEndUnknown = false;
                $this->smtpPhaseStarted = false;
                $this->smtpCallbackOutcome = '';
                $smtp = $this->getSMTPInstance();
                if ( $smtp instanceof PostmanNativeSmtp ) { $smtp->resetPostmanDataOutcome(); }
                $this->ErrorInfo = '';
                list( $postmanMessage, $log ) = $postmanWpMail->createPhpMailerLog( $this );
                // Preserve original native SMTP delivery, but do not offer a
                // replay that would silently drop signing/calendar/envelope state.
                $log->replayUnsupported = ! in_array( $this->ContentType, array( 'text/plain', 'text/html' ), true ) || $this->DKIM_domain !== '' || $this->Ical !== '' || $this->sign_key_file !== '' || ( $this->Sender !== '' && $this->Sender !== $this->From );
            } else {
			// build the message
			$postmanMessage = $postmanWpMail->processWpMailCall( $to, $subject, $body, $headers, $attachments );

			// build the email log entry
			$log = new PostmanEmailLog();
			$log->originalTo = $to;
			$log->originalSubject = $subject;
			$log->originalMessage = $body;
			$log->originalHeaders = $headers;

            }

			// get the transport and create the transportConfig and engine
			$transport = PostmanTransportRegistry::getInstance()->getActiveTransport();

			add_filter( 'postman_wp_mail_result', array( $this, 'postman_wp_mail_result' ) );

			try {
				$response = false;

				if ( $send_email = apply_filters( 'post_smtp_do_send_email', true ) ) {
					if ( $this->options->getTransportType() !== 'smtp' ) {
						$result = $postmanWpMail->send( $to, $subject, $body, $headers, $attachments );
					} else {
                        $frozen = $this->freezeSmtpAttachments();
                        $unsupported = $log->replayUnsupported;
                        list( $postmanMessage, $log ) = $postmanWpMail->createPhpMailerLog( $this, $frozen );
                        $log->replayUnsupported = $unsupported;
                        // A subclass can bypass smtpSend/postSend observation.
                        // Treat entry into its send method as an attempt unless
                        // a trusted native phase proves no delivery occurred.
                        $this->smtpPhaseStarted = true;
						$response = $this->sendSmtp();
                        // PHPMailer with exceptions disabled returns false from
                        // postSend. Route that failure through the same log/result
                        // path, while the outer catch preserves false-return mode.
                        if ( ! $response ) {
                            throw new phpmailerException( $this->ErrorInfo !== '' ? $this->ErrorInfo : 'Native SMTP submission failed.' );
                        }
						$result = $response;
						if ( $response ) {
							do_action( 'post_smtp_on_success', $log, $postmanMessage, $this->transcript, $transport );
						}
					}
				}

				return $result;

			} catch ( Throwable $exc ) {
				$this->error = $exc;
                if ( $this->options->getTransportType() === 'smtp' ) {
                    $smtp_error = $this->getSMTPInstance()->getError();
                    if ( isset( $smtp_error['error'] ) && $smtp_error['error'] === 'DATA END command failed' ) {
                        $code = isset( $smtp_error['smtp_code'] ) ? (int) $smtp_error['smtp_code'] : 0;
                        $this->smtpDataEndUnknown = $code < 400 || $code >= 600;
                    }
                }
                if ( $this->options->getTransportType() === 'smtp' && $this->getSMTPInstance() instanceof PostmanNativeSmtp ) {
                    $outcome = $this->getSMTPInstance()->getPostmanDataOutcome();
                    if ( $outcome === 'accepted' ) {
                        // The message reached at least one recipient. Holding the
                        // whole replay avoids sending it to that recipient again.
                        $this->transcript .= "\nSMTP DATA accepted before send failure; automatic replay held.";
                    }
                    $this->smtpDataEndUnknown = $this->smtpDataEndUnknown || $outcome === 'accepted' || $outcome === 'unknown';
                }
                if ( $this->options->getTransportType() === 'smtp' && ! ( $this->getSMTPInstance() instanceof PostmanNativeSmtp ) && $this->smtpPhaseStarted ) {
                    if ( $this->smtpCallbackOutcome === 'accepted' ) {
                        $this->transcript .= "\nSMTP DATA accepted before send failure; automatic replay held.";
                    }
                    // A caller-supplied SMTP implementation has no DATA observer.
                    // Keep its identity/configuration; incomplete callbacks cannot
                    // prove rejection, especially after RSET/QUIT or callback errors.
                    $this->smtpDataEndUnknown = $this->smtpDataEndUnknown || $this->smtpCallbackOutcome !== 'not-sent';
                }
                if ( $this->options->getTransportType() === 'smtp' && $this->smtpDataEndUnknown ) {
                    $this->transcript .= "\nSMTP delivery outcome unknown.";
                }
				$this->mailHeader = '';
				$this->setError( $exc->getMessage() );

				// Only fire post_smtp_on_failed if we handled SMTP directly (otherwise PostmanWpMail already fired it)
				if ( $this->options->getTransportType() === 'smtp' ) {
					do_action( 'post_smtp_on_failed', $log, $postmanMessage, $this->transcript, $transport, $exc->getMessage() );
				}

                // Error objects remain programming failures even when PHPMailer
                // exceptions are disabled. Persist SMTP evidence, then propagate
                // the same throwable instead of converting it to false.
                if ( $this->exceptions || ! ( $exc instanceof Exception ) ) {
					throw $exc;
				}
				return false;
			} finally {
                // File contents must not leak into a later native/API send.
                $this->smtpAttachmentBytes = null;
                PostmanWpMail::registerCompletedResult( $this );
            }
		}

        public function smtpConnect( $options = null ) {
            try {
                $connected = parent::smtpConnect( $options );
                if ( ! $connected && $this->smtpCallbackPhase ) { $this->smtpCallbackOutcome = 'not-sent'; }
                return $connected;
            } catch ( Throwable $error ) {
                // smtpConnect completes before MAIL/RCPT/DATA. Do not infer
                // this phase from localized connection-error message text.
                if ( $this->smtpCallbackPhase ) { $this->smtpCallbackOutcome = 'not-sent'; }
                throw $error;
            }
        }

        protected function smtpSend( $header, $body ) {
            $this->smtpPhaseStarted = true;
            $this->smtpCallbackPhase = true;
            $this->smtpCallbackSeen = 0;
            $this->smtpCallbackExpected = count( $this->all_recipients );
            $this->smtpCallbackAccepted = false;
            $this->smtpCallbackOutcome = '';
            try {
                return parent::smtpSend( $header, $body );
            } catch ( Throwable $error ) {
                // Read definite pre-delivery rejection before postSend's keepalive
                // cleanup can erase SMTP error state. RCPT errors alone do not
                // prove no delivery: DATA may already have reached other RCPTs.
                $smtp_error = $this->getSMTPInstance()->getError();
                $code = isset( $smtp_error['smtp_code'] ) ? (int) $smtp_error['smtp_code'] : 0;
                $command = isset( $smtp_error['error'] ) ? $smtp_error['error'] : '';
                if ( ( $code >= 400 && $code < 600 && in_array( $command, array( 'MAIL FROM command failed', 'DATA command failed', 'DATA END command failed' ), true ) ) ) {
                    $this->smtpCallbackOutcome = 'not-sent';
                }
                throw $error;
            } finally {
                // Pinned smtpSend calls these only after DATA succeeded or when
                // every RCPT failed and DATA was skipped. Observe before user code.
                if ( $this->smtpCallbackAccepted ) {
                    $this->smtpCallbackOutcome = 'accepted';
                } elseif ( $this->smtpCallbackExpected > 0 && $this->smtpCallbackSeen === $this->smtpCallbackExpected ) {
                    $this->smtpCallbackOutcome = 'not-sent';
                }
                $this->smtpCallbackPhase = false;
            }
        }

        protected function doCallback( $isSent, $to, $cc, $bcc, $subject, $body, $from, $extra ) {
            if ( $this->smtpCallbackPhase ) {
                ++$this->smtpCallbackSeen;
                $this->smtpCallbackAccepted = $this->smtpCallbackAccepted || (bool) $isSent;
            }
            return parent::doCallback( $isSent, $to, $cc, $bcc, $subject, $body, $from, $extra );
        }

        /** Match native attachAll hash/CID suppression before storing replay parts. */
        public function getEffectiveAttachments() {
            $parts = array();
            $included = array();
            $cids = array();
            foreach ( $this->getAttachments() as $part ) {
                $group = $part[6];
                $hash = hash( 'sha256', serialize( $part ) );
                if ( isset( $included[$group][$hash] ) ) { continue; }
                $included[$group][$hash] = true;
                if ( $group === 'inline' && isset( $cids[$part[7]] ) ) { continue; }
                if ( $group === 'inline' ) { $cids[$part[7]] = true; }
                $parts[] = $part;
            }
            return $parts;
        }

        /** Preserve snapshot part count without changing public MIME filenames. */
        public function addSnapshotAttachment( $part ) {
            if ( count( $part ) !== 8 || ! $part[5] || strpos( $part[1], 'post-smtp-snapshot:' ) !== 0 ) {
                throw new phpmailerException( 'Post SMTP invalid snapshot attachment identity.' );
            }
            $index = count( $this->attachment );
            if ( $part[6] === 'inline' ) {
                $ok = $this->addStringEmbeddedImage( $part[0], $part[7], $part[2], $part[3], $part[4], $part[6] );
            } else {
                $ok = $this->addStringAttachment( $part[0], $part[2], $part[3], $part[4], $part[6] );
            }
            if ( $ok ) {
                // Tuple[1] participates in PHPMailer's hash but is not a MIME
                // filename. Snapshot ordinals retain distinct identical parts.
                $this->attachment[$index][1] = $part[1];
            }
            return $ok;
        }

        /** Read file attachments once without changing native tuples or deduplication. */
        private function freezeSmtpAttachments() {
            $bytes = array();
            $attachments = $this->getEffectiveAttachments();
            foreach ( $attachments as &$part ) {
                if ( ! $part[5] ) {
                    $path = $part[0];
                    if ( ! array_key_exists( $path, $bytes ) ) {
                        if ( ! static::fileIsAccessible( $path ) ) {
                            throw new phpmailerException( 'Post SMTP could not read a native SMTP attachment before submission.' );
                        }
                        $data = @file_get_contents( $path );
                        if ( $data === false ) {
                            throw new phpmailerException( 'Post SMTP could not read a native SMTP attachment before submission.' );
                        }
                        $bytes[$path] = $data;
                    }
                    // The log gets private immutable string tuples; native
                    // PHPMailer keeps the original path tuples and their identity.
                    $part[0] = $bytes[$path];
                    $part[5] = true;
                }
            }
            unset( $part );
            $this->smtpAttachmentBytes = $bytes;
            return $attachments;
        }

        protected function encodeFile( $path, $encoding = 'base64' ) {
            if ( $this->smtpAttachmentBytes !== null && array_key_exists( $path, $this->smtpAttachmentBytes ) ) {
                return $this->encodeString( $this->smtpAttachmentBytes[$path], $encoding );
            }
            return parent::encodeFile( $path, $encoding );
        }

        public function sendSmtp() {
            // Only the pinned native preSend contract proves no transmission.
            // A caller override may submit DATA itself before returning false.
            $native_preflight = ( new ReflectionMethod( $this, 'preSend' ) )->getDeclaringClass()->getName() === ( new ReflectionClass( 'PHPMailer' ) )->getName();
            try {
                $prepared = $this->preSend();
            } catch ( Throwable $error ) {
                if ( $native_preflight ) { $this->smtpCallbackOutcome = 'not-sent'; }
                throw $error;
            }
            if ( ! $prepared ) {
                if ( $native_preflight ) { $this->smtpCallbackOutcome = 'not-sent'; }
                return false;
            }
            return $this->postSend();
        }

		public function postman_wp_mail_result() {
			$result = array(
				'time'       => '',
				'exception'  => $this->error,
				'transcript' => $this->transcript,
			);
			return $result;
		}

	}

}