<?php
/**
 * A facade class with wrapping functions to administer a dropbox account
 *
 * @copyright Copyright (C) 2011 Michael De Wildt. All rights reserved.
 * @author Michael De Wildt (http://www.mikeyd.com.au/)
 * @license This program is free software; you can redistribute it and/or modify
 *          it under the terms of the GNU General Public License as published by
 *          the Free Software Foundation; either version 2 of the License, or
 *          (at your option) any later version.
 *
 *          This program is distributed in the hope that it will be useful,
 *          but WITHOUT ANY WARRANTY; without even the implied warranty of
 *          MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *          GNU General Public License for more details.
 *
 *          You should have received a copy of the GNU General Public License
 *          along with this program; if not, write to the Free Software
 *          Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA.
 */
include( 'Dropbox_API/autoload.php' );
class Dropbox_Facade {

    const CONSUMER_KEY = '0kopgx3zvfd0876';
    const CONSUMER_SECRET = 'grpp5f0dai90bon';

    /**
     * @var Dropbox_API
     */
    private $dropbox = null;

    /**
     * The users Dropbox tokens
     * @var object
     */
    private $tokens = null;

    /**
     * Create a new instance of the Dropbox facade by connecting to Dropbox with the application tokens and then creates
     * a new instance of the dropbox api for use by ths facade
	 *
	 * Set the $delete_pending_authorization parameter to true if the user has accepted their authorization tokens. It
	 * will remove the pending authorization flag from the database. If there is an issue getting the access tokens
	 * from Dropbox then the flag will be reinstated and the user will need to authorize again.
	 *
	 * @param boolean $delete_pending_authorization
     * @return void
     */
    function __construct() {
        $this->tokens = get_option( 'backup-to-dropbox-tokens' );
        if ( !$this->tokens ) {
            $this->tokens = array( 'access' => false, 'request' => false );
            add_option( 'backup-to-dropbox-tokens', $this->tokens, null, 'no' );
        } else {
            //Get the users drop box credentials
            $oauth = new Dropbox_OAuth_PEAR( self::CONSUMER_KEY, self::CONSUMER_SECRET );

			//If we have not an access token then we need to grab one
            if ( $this->tokens['access'] == false ) {
                try {
                    $oauth->setToken( $this->tokens['request'] );
                	$this->tokens['access'] = $oauth->getAccessToken();
                	$this->save_tokens();
				} catch ( Exception $e ) {
					//Authorization failed so we are still pending
					$this->tokens['access'] = false;
				}
            } else {
                $oauth->setToken( $this->tokens['access'] );
            }
            $this->dropbox = new Dropbox_API( $oauth );
        }
    }

    /**
     * If we have successfully retrieved the users email and password from the wp options table then this uer is authorized
     * @return bool
     */
    public function is_authorized() {
        return $this->tokens['access'] && $this->get_account_info();
    }

    /**
     * Returns the Dropbox authorization url
     * @return string
     */
    public function get_authorize_url() {
        $oauth = new Dropbox_OAuth_PEAR( self::CONSUMER_KEY, self::CONSUMER_SECRET );
        $this->tokens['request'] = $oauth->getRequestToken();
        $this->save_tokens();
        return $oauth->getAuthorizeUrl();
    }

    /**
     * Return the users Dropbox info
     * @return void
     */
    public function get_account_info() {
        return $this->dropbox->getAccountInfo();
    }

    private function save_tokens() {
        update_option( 'backup-to-dropbox-tokens', $this->tokens );
    }

    /**
     * Uploads the backup to Dropbox
     * @param  $path - The upload path
     * @param  $file - The location of the file on this server
     * @return bool
     */
    function upload_backup( $path, $file ) {
        if ( !file_exists( $file ) ) {
            throw new Exception( __( 'backup file does not exist.' ) );
        }
        $ret = $this->dropbox->putFile( $path, $file );
        if ( $ret['httpStatus'] != 200 ) {
            throw new Exception(sprintf(__( 'error while uploading the backup to Dropbox. HTTP Status: %s, Body: %s'),
									$ret['httpStatus'],
									$ret['body']));
        }
    }

    /**
     * Deletes any backup files greater then the max value passed
     * @param  $path string - The path of the backup files
     * @param  $max int - The maximum amount of backups to keep
     * @return void
     */
    public function purge_backups( $path, $max ) {
        $meta_data = $this->dropbox->getMetaData( $path );
        $contents = $meta_data['contents'];
        if ( $max ) {
            //Grab the backups that are currently on the server and make sure they are the correct file name
            $backups = array();
            foreach ( $contents as $file_meta_data ) {
                if ( !$file_meta_data['is_dir'] && preg_match( '/wordpress-backup-\d{4}-\d{2}-\d{2}.zip/', $file_meta_data['path'] ) ) {
                    $backups[] = $file_meta_data['path'];
                }
            }
        }

        //Sort the backups and remove any old backups greater then the max
        asort( $backups );
        $count = count( $backups );
        if ( $count > $max ) {
            $diff = $count - $max;
            for ( $i = 0; $i < $diff; $i++ ) {
                $this->dropbox->delete( $backups[$i] );
            }
        }
    }
}
