<?php
/**
 * A class with functions the perform a backup of WordPress
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
class WP_Backup {

	const BACKUP_STATUS_STARTED = 0;
	const BACKUP_STATUS_SUCCESS = 1;
	const BACKUP_STATUS_ERROR = 2;

    /**
     * The users Dropbox options
     * @var object
     */
    private $options = null;

    /**
     * The users backup schedule
     * @var object
     */
    private $schedule = null;

    /**
     * The users backup history
     * @var object
     */
    private $history = null;

    /**
     * Construct the Backup class and pre load the schedule, history and options
     * @return void
     */
    public function __construct() {
        //Load the history
        $this->history = get_option( 'backup-to-dropbox-history' );
        if ( !$this->history ) {
            add_option( 'backup-to-dropbox-history', array(), null, 'no' );
            $this->history = array();
        }
        krsort( $this->history );

        //Load the options
        $this->options = get_option( 'backup-to-dropbox-options' );
        if ( !$this->options ) {
            //Options: Local backup location, Dropbox backup location, Keep local backups, Max backups to keep
            $this->options = array( 'wp-content/backups', 'WordPressBackup', true, 6 );
            add_option( 'backup-to-dropbox-options', $this->options, null, 'no' );
        }

        //Load the schedule
        $time = wp_next_scheduled( 'execute_periodic_drobox_backup' );
		$frequency = wp_get_schedule( 'execute_periodic_drobox_backup' );
        if ( $time && $frequency ) {
			//Convert the time to the blogs timezone
			$blog_time = strtotime( current_time( 'mysql' ) ) + ( $time - time() );
			$this->schedule = array( $blog_time, $frequency );
        }
    }

    /**
     * Zips up all the files within this wordpress installation, compresses them and then saves the compressed
     * archive on the server.
     * Source: http://stackoverflow.com/questions/1334613/how-to-recursively-zip-a-directory-in-php
     * @return string - Path to the database dump
     */
    public function backup_website() {
        $source = ABSPATH;
        list( $dump_location, , , ) = $this->get_options();
        $date = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
        $destination = "$source/$dump_location/website-backup-$date.zip";
        $exclude = $source . $dump_location . '/';
        if ( PHP_OS == 'WINNT' ) {
            $exclude = str_replace( '/', '\\', $exclude );
        }
        if ( extension_loaded( 'zip' ) === true ) {
            if ( file_exists( $source ) === true ) {
                $zip = new ZipArchive();
                if ( $zip->open( $destination, ZIPARCHIVE::CREATE ) === true ) {
                    $source = realpath( $source );
                    if ( is_dir( $source ) === true ) {
                        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source ), RecursiveIteratorIterator::SELF_FIRST );
                        foreach ( $files as $file ) {
                            $file = realpath( $file );
                            if ( !strstr( $file, $exclude ) ) {
                                if ( is_dir( $file ) === true ) {
                                    $zip->addEmptyDir( str_replace( $source . '/', '', $file . '/' ) );
                                } else if ( is_file( $file ) === true ) {
                                    $zip->addFromString( str_replace( $source . '/', '', $file ), file_get_contents( $file ) );
                                }
                            }
                        }
                    } else if ( is_file( $source ) === true ) {
                        $zip->addFromString( basename( $source ), file_get_contents( $source ) );
                    }
                }
                if ( $zip->close() ) {
                    return $destination;
                }
            }
        }
        throw new Exception( __( 'error while creating the website archive' ) );
    }

    /**
     * Backs up the current wordpress database and saves it to
     * @return string
     */
    public function backup_database() {
        global $wpdb;

        $db_error = __( 'error while accessing database.' );
        $file_error = __( 'error while creating database archive.' );

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        if ( $tables === false ) {
            throw new Exception( $db_error . ' (DB_01)' );
        }

        //Some header information 
        $out = "-- WordPress Backup to Dropbox SQL Dump\n";
        $out .= "-- Version " . BACKUP_TO_DROPBOX_VERSION . "\n";
        $out .= "-- http://www.mikeyd.com.au/wordpress-backup-to-dropbox/\n";
        $out .= "-- Generation Time: " . date( "F j, Y" ) . " at " . date( "H:i" ) . "\n\n";
        $out .= 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' . "\n\n";

        //I got this out of the phpMyAdmin database dump to make sure charset is correct
        $out .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $out .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $out .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $out .= "/*!40101 SET NAMES utf8 */;\n\n";

        foreach ( $tables as $t ) {
            $table = $t[0];

            //Header comment
            $out .= "--\n-- Table structure for table `$table`\n--\n\n";

            //Print the create table statement
            $table_create = $wpdb->get_row( "SHOW CREATE TABLE $table", ARRAY_N );
            if ( $table_create === false ) {
                throw new Exception( $db_error . ' (DB_02)' );
            }
            $out .= $table_create[1] . ";\n\n";

            //Print the insert data statements
            $table_data = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
            if ( $table_data === false ) {
                throw new Exception( $db_error . ' (DB_03)' );
            }

            //Data comment
            $out .= "--\n-- Dumping data for table `$table`\n--\n\n";

            $fields = '`' . implode( '`, `', array_keys( $table_data[0] ) ) . '`';
            $out .= "INSERT INTO `$table` ($fields) VALUES \n";

            foreach ( $table_data as $data ) {
                $data_out = '(';
                foreach ( $data as $value ) {
                    $value = addslashes( $value );
                    $value = str_replace( "\n", "\\n", $value );
                    $value = str_replace( "\r", "\\r", $value );
                    $data_out .= "'$value', ";
                }
                $out .= rtrim( $data_out, ' ,' ) . "),\n";
            }
            $out = rtrim( $out, ",\n" ) . ";\n";
        }

        list( $dump_location, , , ) = $this->get_options();
        $date = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
        $filename = ABSPATH . $dump_location . "/db-backup-$date.sql";
        $handle = fopen( $filename, 'w+' );
        if ( !$handle ) {
            throw new Exception( $file_error . ' (FS_01)' );
        }
        $ret = fwrite( $handle, $out );
        if ( !$ret ) {
            throw new Exception( $file_error . ' (FS_02)'  );
        }
        $ret = fclose( $handle );
        if ( !$ret ) {
            throw new Exception( $file_error . ' (FS_03)'  );
        }

        return $filename;
    }

	/**
	 * Schedules a backup to start now
	 * @return void
	 */
	public function backup_now() {
		wp_schedule_single_event( time(), 'execute_periodic_drobox_backup' );
	}

    /**
     * Sets the day, time and frequency a wordpress backup is to be performed
     * @param  $day
     * @param  $time
     * @param  $frequency
     * @return void
     */
    public function set_schedule( $day, $time, $frequency ) {
        $blog_time = strtotime( current_time( 'mysql' ) );

        //Grab the date in the blogs timezone
        $date = date( 'Y-m-d', $blog_time );

        //Check if we need to schedule the backup in the future
        $time_arr = explode( ':', $time );
        $current_day = date( 'D', $blog_time );
        if ( $current_day != $day ) {
            $date = date( 'Y-m-d', strtotime( "next $day" ) );
        } else if ( (int)$time_arr[0] <= (int)date( 'H', $blog_time ) ) {
			$date = date( 'Y-m-d', strtotime( "+7 days" ) );
		}

        $timestamp = wp_next_scheduled( 'execute_periodic_drobox_backup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'execute_periodic_drobox_backup' );
        }

        //This will be in the blogs timezone
        $scheduled_time = strtotime( $date . ' ' . $time );

        //Convert the selected time to that of the server
        $server_time = time() + ( $scheduled_time - $blog_time );

        wp_schedule_event( $server_time, $frequency, 'execute_periodic_drobox_backup' );
		
        $this->schedule = array( $scheduled_time, $frequency );
    }

    /**
     * Return the backup schedule
     * @return array - day, time, frequency
     */
    public function get_schedule() {
        return $this->schedule;
    }

    /**
     * Set the dropbox backup options
     * @param  $dump_location - Local backup location
     * @param  $dropbox_location - Dropbox backup location,
     * @param  $keep_local - Keep local backups
     * @param  $backup_count - Max backups to keep
     * @return void
     */
    public function set_options( $dump_location, $dropbox_location, $keep_local, $backup_count ) {
        if ( $backup_count < 1 ) {
            $backup_count = 1;
        }

        //The backup count has decreased so we need to purge some of the history
        if ( $this->options[3] > $backup_count ) {
            $diff = $this->options[3] - $backup_count;
            for ( $i = 0; $i < $diff; $i++ ) {
                array_pop( $this->history );
				array_pop( $this->history );
            }
            $this->purge_backups( $diff );
            update_option( 'backup-to-dropbox-history', $this->history );
        }

        $this->options = array( $dump_location, $dropbox_location, $keep_local, $backup_count );
        update_option( 'backup-to-dropbox-options', $this->options );
    }

    /**
     * Removes a number of local backups
     * @param  $count
     * @return void
     */
    public function purge_backups() {
        list( $dump_location, , , $max ) = $this->get_options();
        if ( $max > 0 ) {
            $path = ABSPATH . '/' . $dump_location;
            $dir = opendir( $path );
            $backups = array();
            while ( $file = readdir( $dir ) ) {
                if ( $file == '.' || $file == '..' ) {
                    continue;
                }
                $backups[] = $file;
            }
            asort( $backups );
            $count = count( $backups );
            if ( $count > $max ) {
                $diff = $count - $max;
                for ( $i = 0; $i < $diff; $i++ ) {
                    unlink( $path . '/' . $backups[$i] );
                }
            }
        }
    }

    /**
     * Get the dropbox backup options if we don't have any options set the defaults
     * @return array - Dump location, Dropbox location, Keep local, Backup count
     */
    public function get_options() {
        return $this->options;
    }

    /**
     * Returns the backup history of this wordpress installation
     * @return array - time, success
     */
    public function get_history() {
        return $this->history;
    }

    /**
     * Updates the backup history option
     * @param  $success
     * @param  $msg
     * @return void
     */
    public function set_history( $status, $msg = null ) {
        list( , , , $count ) = $this->get_options();
        //We only want to keep the history of the backups we have stored
        if ( count( $this->history ) >= ( $count * 2 ) ) {
            array_pop( $this->history );
			array_pop( $this->history );
        }
        $this->history[strtotime( current_time( 'mysql' ) )] = array( $status, $msg );
		krsort($this->history);
        update_option( 'backup-to-dropbox-history', $this->history );
    }

    /**
     * Execute the backup
     * @return bool
     */
    public function execute() {
		$db_success = $ws_success = $success = false;
        list( $dump_location, , , ) = $this->get_options();
        $date = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
        $file = "wordpress-backup-$date.zip";
        $dump_dir = ABSPATH . $dump_location;
        $destination = $dump_dir . '/' . $file;

        //Check that the dump location directory exists
        if ( !file_exists( $dump_dir ) ) {
            if ( !mkdir( $dump_dir ) ) {
                throw new Exception( __( 'error while creating the local dump directory.' ) );
            }
        }

        $zip = new ZipArchive();
        if ( $zip->open( $destination, ZIPARCHIVE::CREATE ) === true ) {
            $db_file = $this->backup_database();
            $ws_file = $this->backup_website();

            $db_success = $zip->addFromString( basename( $db_file ), file_get_contents( $db_file ) );
            $ws_success = $zip->addFromString( basename( $ws_file ), file_get_contents( $ws_file ) );
            $success = $zip->close();
            if ( $db_success && $ws_success && $success ) {
                //Delete the old files as they are now in the archive
                unlink( $db_file );
                unlink( $ws_file );
            }
        }
        return ( $db_success && $ws_success && $success ) ? $file : false;
    }
}
