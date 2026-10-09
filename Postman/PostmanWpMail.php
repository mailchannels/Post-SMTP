<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'PostmanWpMail' ) ) {

	/**
	 * Moved this code into a class so it could be used by both wp_mail() and PostmanSendTestEmailController
	 *
	 * @author jasonhendriks
	 */
	class PostmanWpMail {
		private $exception;
		private $transcript;
		private $totalTime;
		private $logger;

		/**
		 * Load the dependencies
		 */
		public function init() {
			$this->logger = new PostmanLogger( get_class( $this ) );
			require_once 'Postman-Mail/PostmanMessage.php';
			require_once 'Postman-Email-Log/PostmanEmailLogService.php';
			require_once 'Postman-Mail/PostmanMailEngine.php';
			require_once 'Postman-Auth/PostmanAuthenticationManagerFactory.php';
			require_once 'PostmanState.php';

			PostmanEmailLogService::getInstance();
		}

		/**
		 * This methods follows the wp_mail function interface, but implements it Postman-style.
		 * Exceptions are held for later inspection.
		 * An instance of PostmanState updates the success/fail tally.
		 *
		 * @param mixed $to
		 * @param mixed $subject
		 * @param mixed $body
		 * @param mixed $headers
		 * @param mixed $attachments
		 * @return boolean
		 */
		public function send( $to, $subject, $message, $headers = '', $attachments = array(), $embeds = array(), $applyMailFilter = true, $replayBodies = null ) {

			// initialize for sending
			$this->init();

			// build the message
			$postmanMessage = $this->processWpMailCall( $to, $subject, $message, $headers, $attachments, $embeds, $applyMailFilter );
            if ( $replayBodies !== null ) {
                $postmanMessage->setContentType( 'text/html' );
                $postmanMessage->setCharset( $replayBodies['charset'] );
                $postmanMessage->setBody( $replayBodies['html'] );
                $postmanMessage->setBodyHtmlPart( $replayBodies['html'] );
                $postmanMessage->setBodyTextPart( $replayBodies['text'] );
            }


			/*
			 * Build the email log entry from the *final* message, after all wp_mail
			 * filters and header parsing have been applied.
			 *
			 * This makes the log reflect what was actually sent to the recipient
			 * (subject, body, headers, recipients), instead of the raw wp_mail()
			 * arguments.
			 */
			$log = new PostmanEmailLog();
			$log->originalTo      = $this->format_recipients_for_log( $postmanMessage->getToRecipients() );
			$log->originalSubject = $postmanMessage->getSubject();
			$log->originalMessage = $postmanMessage->getBody();
			$log->originalHeaders = $this->flatten_headers_for_log( $postmanMessage );

			// send the message and return the result
			return $this->sendMessage( $postmanMessage, $log );
		}

        /** Core has already filtered and initialized this PHPMailer message. */
        public function sendPhpMailer( $mailer ) {
            $this->init();
            add_filter( 'postman_wp_mail_result', array( $this, 'postman_wp_mail_result' ) );
            try {
                if ( ! in_array( $mailer->ContentType, array( 'text/plain', 'text/html' ), true ) || count( $mailer->getReplyToAddresses() ) > 1 ) {
                    throw new phpmailerException( 'MailChannels cannot preserve this PHPMailer content type or multiple Reply-To addresses.' );
                }
                if ( $mailer->DKIM_domain !== '' || $mailer->Ical !== '' || ( $mailer->Sender !== '' && $mailer->Sender !== $mailer->From ) ) {
                    throw new phpmailerException( 'MailChannels cannot preserve PHPMailer signing, calendar or a separate envelope sender.' );
                }
                list( $message, $log ) = $this->createPhpMailerLog( $mailer );
            } catch ( Exception $error ) {
                // The result hook must describe validation failures too, so
                // AJAX replay can report the error without dereferencing null.
                $this->exception = $error;
                $this->transcript = 'PHPMailer validation failed before MailChannels submission.';
                throw $error;
            }
            // Keep plugin forced-sender/recipient policy; core owns WordPress hooks.
            return $this->sendMessage( $message, $log, false );
        }

        /** Snapshot final native state for either API submission or SMTP logging. */
        public function createPhpMailerLog( $mailer, $attachments = null ) {
            $message = new PostmanMessage();
            $message->setFrom( $mailer->From, $mailer->FromName );
            foreach ( array( 'addTo'=>'getToAddresses', 'addCc'=>'getCcAddresses', 'addBcc'=>'getBccAddresses' ) as $add => $get ) {
                foreach ( $mailer->$get() as $address ) {
                    $message->$add( array( ( new PostmanEmailAddress( $address[0], $address[1] ) )->format() ) );
                }
            }
            foreach ( $mailer->getReplyToAddresses() as $address ) {
                $message->setReplyTo( ( new PostmanEmailAddress( $address[0], $address[1] ) )->format() );
            }
            $message->setSubject( $mailer->Subject );
            $message->setCharset( $mailer->CharSet );
            $message->setContentType( $mailer->ContentType );
            $message->setBody( $mailer->Body );
            if ( $mailer->ContentType === 'text/html' || $mailer->AltBody !== '' ) {
                $message->setBodyHtmlPart( $mailer->Body );
                if ( $mailer->AltBody !== '' ) { $message->setBodyTextPart( $mailer->AltBody ); }
            } else { $message->setBodyTextPart( $mailer->Body ); }
            $attachments = $attachments === null ? $mailer->getAttachments() : $attachments;
            foreach ( $attachments as &$attachment ) {
                if ( $attachment[6] === 'attachment' && $attachment[7] === 0 ) { $attachment[7] = ''; }
            }
            unset( $attachment );
            $message->setAttachments( $attachments );
            foreach ( $mailer->getCustomHeaders() as $header ) { $message->addHeaders( array( $header[0] . ': ' . $header[1] ) ); }
            $log = new PostmanEmailLog();
            $log->originalTo = $this->format_recipients_for_log( $message->getToRecipients() );
            $log->originalSubject = $message->getSubject();
            $log->originalMessage = $message->getBody();
            $headers = array( $this->flatten_headers_for_log( $message ), 'From: ' . $message->getFromAddress()->format() );
            if ( count( $mailer->getReplyToAddresses() ) > 1 ) {
                $headers = array_values( array_filter( preg_split( '/\r\n|\r|\n/', implode( "\r\n", $headers ) ), static function ( $header ) { return stripos( $header, 'Reply-To:' ) !== 0; } ) );
                $reply = array();
                foreach ( $mailer->getReplyToAddresses() as $address ) { $reply[] = ( new PostmanEmailAddress( $address[0], $address[1] ) )->format(); }
                $headers[] = 'Reply-To: ' . implode( ', ', $reply );
            }

            foreach ( array( 'Cc'=>'getCcRecipients', 'Bcc'=>'getBccRecipients' ) as $name => $get ) {
                $value = $this->format_recipients_for_log( $message->$get() );
                if ( $value !== '' ) { $headers[] = $name . ': ' . $value; }
            }
            $log->originalHeaders = implode( "\r\n", $headers );
            return array( $message, $log );
        }

		/**
		 * Convert PostmanEmailAddress recipients to a string suitable for logging
		 * and for passing back into wp_mail() when resending.
		 *
		 * @param array $recipients Array of PostmanEmailAddress objects.
		 *
		 * @return string Comma separated list of recipients in "Name <email>" format.
		 */
		private function format_recipients_for_log( $recipients ) {
			if ( empty( $recipients ) || ! is_array( $recipients ) ) {
				return '';
			}

			$emails = array();

			foreach ( $recipients as $recipient ) {
				if ( $recipient instanceof PostmanEmailAddress ) {
					$emails[] = $recipient->format();
				}
			}

			return implode( ', ', $emails );
		}

		/**
		 * Build a wp_mail()-compatible headers string from the PostmanMessage.
		 *
		 * Historically we stored the original $headers argument exactly as it was
		 * passed into wp_mail(). To keep resend behaviour working while still
		 * reflecting the final email, we reconstruct the headers from the parsed
		 * PostmanMessage structure.
		 *
		 * @param PostmanMessage $message
		 *
		 * @return string Header lines separated by "\r\n".
		 */
		private function flatten_headers_for_log( PostmanMessage $message ) {
			$lines = array();

			// Generic headers collected during parsing (X-*, custom headers, etc).
			foreach ( (array) $message->getHeaders() as $header ) {
				if ( isset( $header['name'], $header['content'] ) && '' !== trim( $header['content'] ) ) {
					$lines[] = trim( $header['name'] ) . ': ' . trim( $header['content'] );
				}
			}

			// Explicit Reply-To header if present.
			if ( $message->getReplyTo() instanceof PostmanEmailAddress ) {
				$lines[] = 'Reply-To: ' . $message->getReplyTo()->format();
			}

			// Content-Type + charset.
			if ( $message->getContentType() ) {
				$header = 'Content-Type: ' . $message->getContentType();
				if ( $message->getCharset() ) {
					$header .= '; charset=' . $message->getCharset();
				}
				$lines[] = $header;
			}

			return implode( "\r\n", array_unique( $lines ) );
		}

        /**
         * @param PostmanMessage $message
         * @return PostmanMessage
         */
		private function apply_default_headers( $message ) {
			/**
			 * Filter the generated Message-ID value before the header is sent.
			 *
			 * @since 3.9.2
			 *
			 * @param string $message_id Message-ID generated by createMessageId().
			 */
			$message_id = apply_filters( 'post_smtp_message_id', $this->createMessageId() );
			$headers[] = 'Message-ID: ' . $message_id;
			$message->addHeaders($headers);
		}

		/**
		 * Creates the Message-ID
		 *
		 * @return string
		 */
		public function createMessageId() {

			$id = md5(uniqid(time()));

			if (isset($_SERVER["SERVER_NAME"])) {
				$hostName = sanitize_text_field($_SERVER["SERVER_NAME"]);
			} else {
				$hostName = php_uname('n');
			}

			return $id . '@' . str_replace('www.', '', $hostName);

		}

		/**
		 * Builds a PostmanMessage based on the WordPress wp_mail parameters
		 *
		 * @param mixed $to
		 * @param mixed $subject
		 * @param mixed $message
		 * @param mixed $headers
		 * @param mixed $attachments
		 */
		public function processWpMailCall( $to, $subject, $message, $headers, $attachments, $embeds = array(), $applyMailFilter = true ) {
			$this->logger->trace( 'wp_mail parameters before applying WordPress wp_mail filter:' );
			$this->traceParameters( $to, $subject, $message, $headers, $attachments );

			/**
			 * Filter the wp_mail() arguments.
			 *
			 * @since 1.5.4
			 *
			 * @param array $args
			 *        	A compacted array of wp_mail() arguments, including the "to" email,
			 *        	subject, message, headers, and attachments values.
			 */
			$atts = compact( 'to', 'subject', 'message', 'headers', 'attachments', 'embeds' );
            if ( $applyMailFilter ) { $atts = apply_filters( 'wp_mail', $atts ); }

			if ( isset( $atts ['to'] ) ) {
				$to = $atts ['to'];
			}

			if ( isset( $atts ['subject'] ) ) {
				$subject = $atts ['subject'];
			}

			if ( isset( $atts ['message'] ) ) {
				$message = $atts ['message'];
			}

			if ( isset( $atts ['headers'] ) ) {
				$headers = $atts ['headers'];
			}

			if ( isset( $atts ['attachments'] ) ) {
				$attachments = $atts ['attachments'];
			}

            if ( isset( $atts['embeds'] ) ) { $embeds = $atts['embeds']; }

			if ( ! is_array( $attachments ) ) {
				$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
			}

			$this->logger->trace( 'wp_mail parameters after applying WordPress wp_mail filter:' );
			$this->traceParameters( $to, $subject, $message, $headers, $attachments );

			// Postman API: register the response hook
			add_filter( 'postman_wp_mail_result', array(
					$this,
					'postman_wp_mail_result',
			) );

			// create the message
			$postmanMessage = $this->createNewMessage();
			$this->populateMessageFromWpMailParams( $postmanMessage, $to, $subject, $message, $headers, $attachments );
            $postmanMessage->setEmbeds( $embeds );

			// return the message
			return $postmanMessage;
		}

		/**
		 * Creates a new instance of PostmanMessage with a pre-set From and Reply-To
		 *
		 * @return PostmanMessage
		 */
		public function createNewMessage() {
			$message = new PostmanMessage();
			$options = PostmanOptions::getInstance();
			// the From is set now so that it can be overridden
			$transport = PostmanTransportRegistry::getInstance()->getActiveTransport();
			if ( $transport !== null ) {
				$fromEmail = $transport->getFromEmailAddress();
				$fromName = $transport->getFromName();
			} else {
				// fallback to options or defaults if transport is null
				$fromEmail = $options->getMessageSenderEmail();
				$fromName = $options->getMessageSenderName();
			}
			$message->setFrom( $fromEmail, $fromName );
			// the Reply-To is set now so that it can be overridden
			$message->setReplyTo( $options->getReplyTo() );
			$message->setCharset( get_bloginfo( 'charset' ) );
			return $message;
		}

		/**
		 * A convenient place for any code to inject a constructed PostmanMessage
		 * (for example, from MyMail)
		 *
		 * The body parts may be set already at this time.
		 *
		 * @param PostmanMessage $message
		 * @return boolean
		 */
		public function sendMessage( PostmanMessage $message, PostmanEmailLog $log, $applyWordPressFilters = true ) {

			$this->apply_default_headers( $message );

			// get the Options and AuthToken
			$options = PostmanOptions::getInstance();
			$authorizationToken = PostmanOAuthToken::getInstance();

			// get the transport and create the transportConfig and engine
			$transport = PostmanTransportRegistry::getInstance()->getActiveTransport();

			if ( ! $transport ) {
				$this->logger->error( 'No mail transport available; transports may not be registered yet.' );
				$this->exception = new Exception( 'Post SMTP: no mail transport available.' );
				return false;
			}

			// create the Mail Engine
			$engine = $transport->createMailEngine();

			// add plugin-specific attributes to PostmanMessage
			$message->addHeaders( $options->getAdditionalHeaders() );
			$message->addTo( $options->getForcedToRecipients() );
			$message->addCc( $options->getForcedCcRecipients() );
			$message->addBcc( $options->getForcedBccRecipients() );

			// Ensure originalTo reflects the final list of all \"To\" recipients,
			// including any global \"To Recipient(s)\" configured in Settings.
			$log->originalTo = $this->format_recipients_for_log( $message->getToRecipients() );

			// apply the WordPress filters
			// may impact the from address, from email, charset and content-type
			$message->applyFilters( $applyWordPressFilters );

			// create the body parts (if they are both missing)
			if ( $message->isBodyPartsEmpty() ) {
				$message->createBodyParts();
			}

			// is this a test run?
			$testMode = apply_filters( 'postman_test_email', false );
			if ( $this->logger->isDebug() ) {
				$this->logger->debug( 'testMode=' . $testMode );
			}

			// start the clock
			$startTime = microtime( true ) * 1000;

			try {

				// prepare the message
				$message->validate( $transport );

				// send the message
				if ( $options->getRunMode() == PostmanOptions::RUN_MODE_PRODUCTION ) {
					if ( $transport->isLockingRequired() ) {
						PostmanUtils::lock();
						// may throw an exception attempting to contact the OAuth2 provider
						$this->ensureAuthtokenIsUpdated( $transport, $options, $authorizationToken );
					}

					$this->logger->debug( 'Sending mail' );

					// may throw an exception attempting to contact the SMTP server
					/**
					 * Filters to send email or not
					 * 
					 * @param bool $send_email
					 * @since 2.5.0
					 * @version 1.0.0
					 */
					if ( $send_email = apply_filters( 'post_smtp_do_send_email', true ) && apply_filters( 'post_smtp_send_email', true ) ) {

						$engine->send( $message );

					} else {
						$this->transcript = 'Bypassed By MailControl For Post SMTP';
					}

					// increment the success counter, unless we are just tesitng or sending a notification
					if ( ! $testMode && ! $this->is_notification_message( $message ) ) {
						PostmanState::getInstance()->incrementSuccessfulDelivery();
					}
				}

				// clean up
				$this->postSend( $engine, $startTime, $options, $transport );

				   /**
					* Do stuff after successful delivery
					*/
				   if ($transport !== null) {
					   do_action( 'post_smtp_on_success', $log, $message, $engine->getTranscript(), $transport );
				   } else {
					   if ($this->logger) {
						   $this->logger->warning('Transport is null, skipping post_smtp_on_success action to avoid fatal error.');
					   }
				   }
				   // return successful
				   return true;
			} catch ( Exception $e ) {
				// save the error for later
				$this->exception = $e;

				// write the error to the PHP log
				$this->logger->error( get_class( $e ) . ' code=' . $e->getCode() . ' message=' . trim( $e->getMessage() ) );

				// increment the failure counter, unless we are just tesitng or sending a notification
				if ( ! $testMode && ! $this->is_notification_message( $message ) && $options->getRunMode() == PostmanOptions::RUN_MODE_PRODUCTION ) {
					PostmanState::getInstance()->incrementFailedDelivery();
				}

				// clean up
				$this->postSend( $engine, $startTime, $options, $transport );


				   /**
					* Do stuff after failed delivery
					*/
				   if ($transport !== null) {
					   do_action( 'post_smtp_on_failed', $log, $message, $engine->getTranscript(), $transport, $e->getMessage() );
                       if ( $e instanceof PostmanSmtpDeliveryException ) {
                           PostmanEmailLogService::getInstance()->holdAttemptLogs( $log, PostmanSmtpDeliveryException::OUTCOME_UNKNOWN );
                       }
				   } else {
					   if ($this->logger) {
						   $this->logger->warning('Transport is null, skipping post_smtp_on_failed action to avoid fatal error.');
					   }
				   }

				   // Fallback
				   if ( $this->fallback( $log, $message, $options ) ) {

					   return true;

				}

				$mail_error_data = array(
					'to' => $message->getToRecipients(),
					'subject' => $message->getSubject(),
					'message' => $message->getBody(),
					'headers' => $message->getHeaders(),
					'attachments' => $message->getAttachments()
				);
				$mail_error_data['phpmailer_exception_code'] = $e->getCode();

                if ( $applyWordPressFilters ) { do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_error_data ) ); }

				// return failure
				if ( PostmanOptions::getInstance()->getSmtpMailer() == 'phpmailer' ) {
					throw new phpmailerException($e->getMessage(), $e->getCode());
				}
				return false;

			}
		}

		private function fallback( $log, $postMessage, $options ) {
			if ( ( $this->exception instanceof PostmanMailChannelsDeliveryException || $this->exception instanceof PostmanSmtpDeliveryException ) && $this->exception->isDeliveryUncertain() ) {
				return false; // The primary provider may already have accepted the message.
			}

			// Do not attempt fallback if this is an internal Post SMTP notification
			if ( $this->is_notification_message( $postMessage ) ) {
				return false;
			}

			if ( ! $options->is_fallback && $options->getFallbackIsEnabled() && $options->getFallbackIsEnabled() == 'yes' ) {

				$options->is_fallback = true;

				$status = $this->sendMessage( $postMessage, $log );

				$options->is_fallback = false;

				return $status;

			} else {
				$options->is_fallback = false;
			}

			return false;
		}

		/**
		 * Checks if the message is an internal Post SMTP notification.
		 *
		 * @param PostmanMessage $message
		 * @return bool
		 */
		private function is_notification_message( $message ) {
			if ( class_exists( 'PostmanMailNotify' ) && PostmanMailNotify::is_sending() ) {
				return true;
			}

			if ( ! $message instanceof PostmanMessage ) {
				return false;
			}

			foreach ( (array) $message->getHeaders() as $header ) {
				if ( isset( $header['name'] ) && 0 === strcasecmp( $header['name'], PostmanMailNotify::NOTIFICATION_HEADER ) ) {
					return true;
				}
			}

			$subject = $message->getSubject();
			if ( ! empty( $subject ) && is_string( $subject ) && false !== stripos( $subject, 'Post SMTP email error' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Clean up after sending the mail
		 *
		 * @param PostmanZendMailEngine $engine
		 * @param mixed               $startTime
		 */
		private function postSend( PostmanMailEngine $engine, $startTime, PostmanOptions $options, PostmanModuleTransport $transport ) {
			// save the transcript
			$this->transcript = $engine->getTranscript();

			// log the transcript
			if ( $this->logger->isTrace() ) {
				$this->logger->trace( 'Transcript:' );
				$this->logger->trace( $this->transcript );
			}

			// delete the semaphore
			if ( $transport->isLockingRequired() ) {
				PostmanUtils::unlock();
			}

			// stop the clock
			$endTime = microtime( true ) * 1000;
			$this->totalTime = $endTime - $startTime;
		}

		/**
		 * Returns the result of the last call to send()
		 *
		 * @return multitype:Exception NULL
		 */
		function postman_wp_mail_result() {
			$result = array(
					'time' => $this->totalTime,
					'exception' => $this->exception,
					'transcript' => $this->transcript,
			);
			return $result;
		}

		/**
		 */
		private function ensureAuthtokenIsUpdated( PostmanModuleTransport $transport, PostmanOptions $options, PostmanOAuthToken $authorizationToken ) {
			assert( ! empty( $transport ) );
			assert( ! empty( $options ) );
			assert( ! empty( $authorizationToken ) );
			// ensure the token is up-to-date
			$this->logger->debug( 'Ensuring Access Token is up-to-date' );
			// interact with the Authentication Manager
			$wpMailAuthManager = PostmanAuthenticationManagerFactory::getInstance()->createAuthenticationManager();
			if ( $wpMailAuthManager->isAccessTokenExpired() ) {
				$this->logger->debug( 'Access Token has expired, attempting refresh' );
				$wpMailAuthManager->refreshToken();
				$authorizationToken->save();
			}
		}

		/**
		 * Aggregates all the content into a Message to be sent to the MailEngine
		 *
		 * @param mixed $to
		 * @param mixed $subject
		 * @param mixed $body
		 * @param mixed $headers
		 * @param mixed $attachments
		 */
		private function populateMessageFromWpMailParams( PostmanMessage $message, $to, $subject, $body, $headers, $attachments ) {
			$message->addHeaders( $headers );
			$message->setBody( $body );
			$message->setSubject( $subject );
			$message->addTo( $to );
			$message->setAttachments( $attachments );
			return $message;
		}

		/**
		 * Trace the parameters to aid in debugging
		 *
		 * @param mixed $to
		 * @param mixed $subject
		 * @param mixed $body
		 * @param mixed $headers
		 * @param mixed $attachments
		 */
		private function traceParameters( $to, $subject, $message, $headers, $attachments ) {
			$this->logger->trace( 'to:' );
			$this->logger->trace( $to );
			$this->logger->trace( 'subject:' );
			$this->logger->trace( $subject );
			$this->logger->trace( 'headers:' );
			$this->logger->trace( $headers );
			$this->logger->trace( 'attachments:' );
			$this->logger->trace( $attachments );
			$this->logger->trace( 'message:' );
			$this->logger->trace( $message );
		}
	}
}