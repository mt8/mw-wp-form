<?php
/**
 * Name       : MW WP Form Data
 * Description: mw_wp_form のデータ操作用
 * Version    : 1.3.0
 * Author     : Takashi Kitajima
 * Author URI : http://2inc.org
 * Created    : October 10, 2013
 * Modified   : December 31, 2014
 * License    : GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
class MW_WP_Form_Data {

	/**
	 * $Instance
	 * @var MW_WP_Form_Data
	 */
	protected static $Instance;

	/**
	 * $form_key
	 * フォーム識別子
	 * @var string
	 */
	protected $form_key;

	/**
	 * $data
	 * フォームから送信された内容を保存した配列
	 * @var array
	 */
	protected $data = array();

	/**
	 * $Session
	 * @var MW_WP_Form_Sesion
	 */
	protected $Session;

	/**
	 * $token_name
	 * @var string
	 */
	protected $token_name = 'token';

	/**
	 * $POST
	 * @var array
	 */
	protected $POST = array();

	/**
	 * $FILES
	 * @var array
	 */
	protected $FILES = array();

	/**
	 * __construct
	 * @param string $form_key フォーム識別子
	 * @param array $POST $_POSTを想定
	 * @param array $FILES $_FILESを想定
	 */
	private function __construct( $form_key, array $POST = array(), array $FILES = array() ) {
		$this->form_key = $form_key;
		$this->POST     = $POST;
		$this->FILES    = $FILES;
		$this->Session  = new MW_WP_Form_Session( $form_key );
		$this->data     = $this->Session->gets();
		$this->set_request_valiables( $this->POST );
		$this->set_files_valiables( $this->POST, $this->FILES );
		add_filter( 'mwform_form_end_html', array( $this, 'mwform_form_end_html' ) );
		add_action( 'parse_request'       , array( $this, 'remove_query_vars_from_post' ) );
	}
	
	/**
	 * getInstance
	 * @param string $form_key フォーム識別子
	 * @param array $POST $_POSTを想定
	 * @param array $FILES $_FILESを想定
	 */
	public static function getInstance( $form_key, array $POST = array(), array $FILES = array() ) {
		if ( is_null( self::$Instance ) ) {
			self::$Instance = new self( $form_key, $POST, $FILES );
		}
		return self::$Instance;
	}

	/**
	 * set_request_valiables
	 * @param array $POST $_POSTを想定
	 */
	protected function set_request_valiables( array $POST ) {
		if ( !empty( $POST ) ) {
			$this->sets( stripslashes_deep( $POST ) );
		}
	}

	/**
	 * set_files_valiables
	 * @param array $POST $_POSTを想定
	 * @param array $FILES $_FILESを想定
	 */
	protected function set_files_valiables( array $POST, array $FILES ) {
		$files = array();
		foreach ( $FILES as $key => $file ) {
			if ( !isset( $POST[$key] ) || !empty( $file['name'] ) ) {
				if ( $file['error'] == UPLOAD_ERR_OK && is_uploaded_file( $file['tmp_name'] ) ) {
					$this->set( $key, $file['name'] );
				} else {
					$this->set( $key, '' );
				}
				if ( !empty( $file['name'] ) ) {
					$files[$key] = $file;
				}
			}
		}
		// この条件判定がないと fileSize チェックが正しく動作しない
		if ( $files ) {
			$this->set( MWF_Config::UPLOAD_FILES, $files );
		}
	}

	/**
	 * mwform_form_end_html
	 * @param string $html
	 * @return string $html
	 */
	public function mwform_form_end_html( $html ) {
		$html .= wp_nonce_field( $this->form_key, $this->token_name, true, false );
		return $html;
	}

	/**
	 * check
	 * トークンチェック
	 * @return bool
	 */
	protected function token_check() {
		if ( isset( $_POST[$this->token_name] ) ) {
			$request_token = $_POST[$this->token_name];
		}
		$values = $this->gets();
		if ( isset( $request_token ) && wp_verify_nonce( $request_token, $this->key ) ) {
			$this->set( MWF_Config::COMPLETE_TWICE, true );
			return true;
		} elseif ( empty( $_POST ) && !empty( $values ) && $this->get_raw( MWF_Config::COMPLETE_TWICE ) ) {
			$this->clear_value( MWF_Config::COMPLETE_TWICE );
			return true;
		}
		return false;
	}

	/**
	 * get_post_condition
	 * 送信データからどのページを表示すべきかの状態を判定して返す
	 * ただし実際に表示するページと同じとは限らない（バリデーション通らないとかあるので）
	 * @return string back|confirm|complete|input
	 */
	public function get_post_condition() {
		$backButton    = $this->get_raw( MWF_Config::BACK_BUTTON );
		$confirmButton = $this->get_raw( MWF_Config::CONFIRM_BUTTON );
		if ( $backButton ) {
			return 'back';
		} elseif ( $confirmButton ) {
			return 'confirm';
		} elseif ( !$confirmButton && !$backButton && $this->token_check() ) {
			return 'complete';
		}
		return 'input';
	}

	/**
	 * get_raw
	 * データを取得
	 * @param string $key データのキー
	 * @return string データ
	 */
	public function get_raw( $key ) {
		if ( isset( $this->data[$key] ) ) {
			return $this->data[$key];
		}
	}

	/**
	 * getValues
	 * 全てのデータを取得
	 * @return array データ
	 */
	public function gets() {
		if ( $this->data === null ) {
			return array();
		}
		return $this->data;
	}

	/**
	 * set
	 * データを追加
	 * @param string $key データのキー
	 * @param string $value 値
	 */
	public function set( $key, $value ){
		$this->data[$key] = $value;
		$this->Session->set( $key, $value );
	}

	/**
	 * set
	 * 複数のデータを一括で追加
	 * @param array 値
	 */
	public function sets( array $array ) {
		foreach ( $array as $key => $value ) {
			$this->data[$key] = $value;
			$this->Session->set( $key, $value );
		}
	}

	/**
	 * clear_value
	 * データを消す
	 * @param string $key データのキー
	 */
	public function clear_value( $key ) {
		unset( $this->data[$key] );
		$this->Session->clear_value( $key );
	}

	/**
	 * clear_values
	 * データを消す
	 * @param string $key データのキー
	 */
	public function clear_values() {
		$this->data = array();
		$this->Session->clear_values();
	}

	/**
	 * push
	 * 指定した $key をキーと配列にデータを追加
	 * @param string $key データのキー
	 * @param string $value 値
	 */
	public function push( $key, $value ) {
		$this->data[$key][] = $value;
		$this->Session->push( $key, $value );
	}

	/**
	 * get
	 * 整形済み（メール送信可能な）データを取得
	 * @param string $key データのキー
	 * @return string データ
	 */
	public function get( $key ) {
		if ( isset( $this->data[$key] ) ) {
			if ( is_array( $this->data[$key] ) ) {
				if ( !array_key_exists( 'data', $this->data[$key] ) ) {
					return;
				}
				if ( is_array( $this->data[$key]['data'] ) ) {
					return $this->getSeparatedValue( $key );
				} else {
					return $this->data[$key]['data'];
				}
			} else {
				return $this->data[$key];
			}
		}
	}

	/**
	 * getSeparatorValue
	 * 送られてきたseparatorを返す
	 * @param string $key name属性
	 * @return string
	 */
	public function getSeparatorValue( $key ) {
		$value = $this->get_raw( $key );
		if ( is_array( $value ) && isset( $value['separator'] ) ) {
			return $value['separator'];
		}
	}

	/**
	 * getSeparatedValue
	 * 配列データを整形して返す ( 郵便番号等用 )
	 * @param string $key name属性
	 * @param array $children 選択肢
	 * @return string データ
	 */
	public function getSeparatedValue( $key, array $children = array() ) {
		$separator = $this->getSeparatorValue( $key );
		$value = $this->get_raw( $key );
		if ( is_array( $value ) && isset( $value['data'] ) && is_array( $value['data'] ) && !empty( $separator ) ) {
			if ( $children ) {
				$rightData = array();
				foreach ( $value['data'] as $child ) {
					if ( isset( $children[$child] ) && !in_array( $children[$child], $rightData ) ) {
						$rightData[] = $children[$child];
					}
				}
				return implode( $separator, $rightData );
			} else {
				// すべて空のからのときはimplodeしないように（---がいってしまうため）
				foreach ( $value['data'] as $child ) {
					if ( $child !== '' && $child !== null ) {
						return implode( $separator, $value['data'] );
					}
				}
				return '';
			}
		}
	}

	/**
	 * set_upload_file_keys
	 */
	public function set_upload_file_keys() {
		$upload_file_keys = $this->get_raw( MWF_Config::UPLOAD_FILE_KEYS );
		if ( !$upload_file_keys ) {
			$upload_file_keys = array();
		}

		$wp_upload_dir = wp_upload_dir();
		foreach ( $upload_file_keys as $upload_file_key ) {
			$upload_file_url = $this->get_raw( $upload_file_key );
			if ( $upload_file_url ) {
				$filepath = MWF_Functions::fileurl_to_path( $upload_file_url );
				if ( !file_exists( $filepath ) ) {
					unset( $upload_file_keys[$upload_file_key] );
				}
			}
		}
		$this->set( MWF_Config::UPLOAD_FILE_KEYS, $upload_file_keys );
	}

	/**
	 * push_uploaded_file_keys
	 * アップロードに成功したファイルをフォームデータに格納
	 * @param array $uploaded_files アップロード済みファイルのパスの配列
	 */
	public function push_uploaded_file_keys( array $uploaded_files = array() ) {
		$upload_file_keys = $this->get_raw( MWF_Config::UPLOAD_FILE_KEYS );
		foreach ( $uploaded_files as $key => $upload_file ) {
			$this->set( $key, $upload_file );
			if ( !in_array( $key, $upload_file_keys ) ) {
				$this->push( MWF_Config::UPLOAD_FILE_KEYS, $key );
			}
		}
	}

	/**
	 * remove_query_vars_from_post
	 * WordPressへのリクエストに含まれている、$_POSTの値を削除
	 */
	public function remove_query_vars_from_post( $wp_query ) {
		if ( strtolower( $_SERVER['REQUEST_METHOD'] ) === 'post' && isset( $this->POST['token'] ) ) {
			foreach ( $this->POST as $key => $value ) {
				if ( $key == 'token' ) {
					continue;
				}
				if ( isset( $wp_query->query_vars[$key] ) &&
					 $wp_query->query_vars[$key] === $value &&
					 !empty( $value ) ) {

					$wp_query->query_vars[$key] = '';
				}
			}
		}
	}
}
