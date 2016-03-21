<?php

class UserController extends AppController {

	public $components = array('Session', 'Captcha', 'API');

	function get_captcha() {
		$this->autoRender = false;
		App::import('Component','Captcha');

		//generate random charcters for captcha
		$random = mt_rand(100, 99999);

		//save characters in session
		$this->Session->write('captcha_code', $random);

		$settings = array(
			'characters' => $random,
			'winHeight' => 50,         // captcha image height
			'winWidth' => 220,		   // captcha image width
			'fontSize' => 25,          // captcha image characters fontsize
			'fontPath' => WWW_ROOT.'tahomabd.ttf',    // captcha image font
			'noiseColor' => '#ccc',
			'bgColor' => '#fff',
			'noiseLevel' => '100',
			'textColor' => '#000'
		);

		$img = $this->Captcha->ShowImage($settings);
		echo $img;
	}

	function ajax_register() {
		$this->autoRender = false;
		if($this->request->is('Post')) { // si la requête est bien un post
			if(!empty($this->request->data['pseudo']) && !empty($this->request->data['password']) && !empty($this->request->data['password_confirmation']) && !empty($this->request->data['email'])) { // si tout les champs sont bien remplis

				// Captcha
				if($this->Configuration->getKey('captcha_type') == "2") { // ReCaptcha

					$validCaptcha = $this->Util->isValidReCaptcha($this->request->data['recaptcha'], $this->Util->getIP(), $this->Configuration->getKey('captcha_google_secret'));

				} else {

					$captcha = $this->Session->read('captcha_code');
					$validCaptcha = (!empty($captcha) && $captcha == $this->request->data['captcha']);

				}
				//

				if($validCaptcha) { // on check le captcha déjà
					$this->loadModel('User');
					$isValid = $this->User->validRegister($this->request->data, $this->Util);
					if($isValid === true) { // on vérifie si y'a aucune erreur

						$eventData = $this->request->data;
						$eventData['password'] = $this->Util->password($this->request->data['password'], $this->request->data['pseudo']);
						$event = new CakeEvent('beforeRegister', $this, array('data' => $eventData));
						$this->getEventManager()->dispatch($event);
						if($event->isStopped()) {
							return $event->result;
						}

						// on enregistre
						$userSession = $this->User->register($this->request->data, $this->Util);

						// On envoie le mail de confirmation si demandé
						if($this->Configuration->getKey('confirm_mail_signup')) {

							$confirmCode = substr(md5(uniqid()), 0, 12);

							$emailMsg = $this->Lang->get('EMAIL__CONTENT_CONFIRM_MAIL', array(
								'{LINK}' => Router::url('/user/confirm/').$confirmCode,
								'{IP}' => $this->Util->getIP(),
								'{USERNAME}' => $this->request->data['pseudo'],
								'{DATE}' => $this->Lang->date(date('Y-m-d H:i:s'))
							));

							$email = $this->Util->prepareMail(
								$this->request->data['email'],
								$this->Lang->get('EMAIL__TITLE_CONFIRM_MAIL'),
								$emailMsg
							)->sendMail();

							if($email) {

								$this->User->read(null, $this->User->getLastInsertID());
								$this->User->set(array('confirmed' => $confirmCode));
								$this->User->save();

							}

						}

						if(!$this->Configuration->getKey('confirm_mail_signup_block')) { // si on doit pas bloquer le compte si non confirmé
							// on prépare la connexion
							$this->Session->write('user', $userSession);

							$event = new CakeEvent('onLogin', $this, array('user' => $this->User->getAllFromCurrentUser(), 'register' => true));
					    $this->getEventManager()->dispatch($event);
					    if($event->isStopped()) {
					      return $event->result;
					    }
						}

						// on dis que c'est bon
						echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__REGISTER_SUCCESS')));

					} else { // si c'est pas bon, on envoie le message d'erreur retourné par l'étape de validation
						echo json_encode(array('statut' => false, 'msg' => $this->Lang->get($isValid)));
					}
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('FORM__INVALID_CAPTCHA')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
		}
	}

	function ajax_login() {
		$this->autoRender = false;
		if($this->request->is('Post')) {
			if(!empty($this->request->data['pseudo']) && !empty($this->request->data['password'])) {

				$need_confirmed_email = ($this->Configuration->getKey('confirm_mail_signup') && $this->Configuration->getKey('confirm_mail_signup_block'));

				$login = $this->User->login($this->request->data, $need_confirmed_email, $this->Util);
				if(isset($login['status']) && $login['status'] === true) {

					$event = new CakeEvent('onLogin', $this, array('user' => $this->User->getAllFromCurrentUser()));
					$this->getEventManager()->dispatch($event);
					if($event->isStopped()) {
						return $event->result;
					}

					$this->Session->write('user', $login['session']);

					echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__REGISTER_LOGIN')));

				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get($login)));
				}

			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
		}
	}

	function confirm($code = false) {
		$this->autoRender = false;
		if(isset($code)) {

			$find = $this->User->find('first', array('conditions' => array('confirmed' => $code)));

			if(!empty($find)) {

				$event = new CakeEvent('beforeConfirmAccount', $this, array('user_id' => $find['User']['id']));
				$this->getEventManager()->dispatch($event);
				if($event->isStopped()) {
					return $event->result;
				}

				$this->User->read(null, $find['User']['id']);
				$this->User->set(array('confirmed' => date('Y-m-d H:i:s')));
				$this->User->save();

				$userSession = $find['User']['id'];

				$this->Session->write('user', $userSession);

				$event = new CakeEvent('onLogin', $this, array('user' => $this->User->getAllFromCurrentUser(), 'confirmAccount' => true));
				$this->getEventManager()->dispatch($event);
				if($event->isStopped()) {
					return $event->result;
				}

				$this->redirect(array('action' => 'profile'));

			} else {
				throw new NotFoundException();
			}

		} else {
			throw new NotFoundException();
		}
	}

	function ajax_lostpasswd() {
		$this->layout = null;
		$this->autoRender = false;
		if($this->request->is('ajax')) {
			if(!empty($this->request->data['email'])) {
				$this->loadModel('User');
				if(filter_var($this->request->data['email'], FILTER_VALIDATE_EMAIL)) {
					$search = $this->User->find('first', array('conditions' => array('email' => $this->request->data['email'])));
					if(!empty($search)) {
						$this->loadModel('Lostpassword');
						$key = substr(md5(rand().date('sihYdm')), 0, 10);

						$to = $this->request->data['email'];
						$subject = $this->Lang->get('USER__PASSWORD_RESET_LINK').' | '.$this->Configuration->getKey('name').'';
						$message = $this->Lang->email_reset($this->request->data['email'], $search['User']['pseudo'], $key);


						$event = new CakeEvent('beforeSendResetPassMail', $this, array('user_id' => $search['User']['id'], 'key' => $key));
						$this->getEventManager()->dispatch($event);
						if($event->isStopped()) {
							return $event->result;
						}


						if($this->Util->prepareMail($to, $subject, $message)->sendMail()) {
							$this->Lostpassword->create();
							$this->Lostpassword->set(array(
								'email' => $this->request->data['email'],
								'key' => $key
							));
							$this->Lostpassword->save();
							echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__PASSWORD_FORGOT_EMAIL_SUCCESS')));
						} else {
							echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__INTERNAL_ERROR')));
						}
					} else {
						echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_NOT_FOUND')));
					}
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_EMAIL_NOT_VALID')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
		}
	}

	function ajax_resetpasswd() {
		$this->autoRender = false;
		if($this->request->is('ajax')) {
			if(!empty($this->request->data['password']) AND !empty($this->request->data['password2']) AND !empty($this->request->data['email'])) {

				$reset = $this->User->resetPass($this->request->data, $this->Util);
				if($reset['status'] && $reset['status'] === true) {
					$this->Session->write('user', $reset['session']);

					$this->History->set('RESET_PASSWORD', 'user');

					echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__PASSWORD_RESET_SUCCESS')));
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get($reset)));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
		}
	}

	function logout() {
		$this->autoRender = false;

		$event = new CakeEvent('onLogout', $this, array('session' => $this->Session->read('user')));
		$this->getEventManager()->dispatch($event);
		if($event->isStopped()) {
			return $event->result;
		}

		$this->Session->delete('user');
    $this->redirect($this->referer());
	}

	function uploadSkin() {
		$this->autoRender = false;

		if($this->isConnected && $this->API->can_skin()) {
			if($this->request->is('post')) {

				$skin_max_size = 10000000; // octet

				$this->loadModel('ApiConfiguration');
				$ApiConfiguration = $this->ApiConfiguration->find('first');
				$target_config = $ApiConfiguration['ApiConfiguration']['skin_filename'];

				$filename = substr($target_config, (strrpos($target_config, '/') + 1));
				$filename = str_replace('{PLAYER}', $this->User->getKey('pseudo'), $filename);
				$filename = str_replace('php', '', $filename);
				$filename = str_replace('.', '', $filename);
				$filename = $filename.'.png';

				$target = substr($target_config, 0, (strrpos($target_config, '/') + 1));
				$target = WWW_ROOT.'/'.$target;

				$width_max = $ApiConfiguration['ApiConfiguration']['skin_width']; // pixel
				$height_max = $ApiConfiguration['ApiConfiguration']['skin_height']; // pixel

				$isValidImg = $this->Util->isValidImage($this->request, array('png'), $width_max, $height_max, $skin_max_size);

				if(!$isValidImg['status']) {
					echo json_encode(array('statut' => false, 'msg' => $isValidImg['msg']));
					exit;
				} else {
					$infos = $isValidImg['infos'];
				}

				if(!$this->Util->uploadImage($this->request, $target.$filename)) {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('FORM__ERROR_WHEN_UPLOAD')));
					exit;
				}

	     echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('API__UPLOAD_SKIN_SUCCESS')));

			}

		} else {
			new ForbiddenException();
		}
	}

	function uploadCape() {
		$this->autoRender = false;

		if($this->isConnected && $this->API->can_cape()) {
			if($this->request->is('post')) {

				$cape_max_size = 10000000; // octet

				$this->loadModel('ApiConfiguration');
				$ApiConfiguration = $this->ApiConfiguration->find('first');
				$target_config = $ApiConfiguration['ApiConfiguration']['cape_filename'];

				$filename = substr($target_config, (strrpos($target_config, '/') + 1));
				$filename = str_replace('{PLAYER}', $this->User->getKey('pseudo'), $filename);
				$filename = str_replace('php', '', $filename);
				$filename = str_replace('.', '', $filename);
				$filename = $filename.'.png';

				$target = substr($target_config, 0, (strrpos($target_config, '/') + 1));
				$target = WWW_ROOT.'/'.$target;

				$width_max = $ApiConfiguration['ApiConfiguration']['cape_width']; // pixel
				$height_max = $ApiConfiguration['ApiConfiguration']['cape_height']; // pixel

				$isValidImg = $this->Util->isValidImage($this->request, array('png'), $width_max, $height_max, $cape_max_size);

				if(!$isValidImg['status']) {
					echo json_encode(array('statut' => false, 'msg' => $isValidImg['msg']));
					exit;
				} else {
					$infos = $isValidImg['infos'];
				}

				if(!$this->Util->uploadImage($this->request, $target.$filename)) {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('FORM__ERROR_WHEN_UPLOAD')));
					exit;
				}

	     echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('API__UPLOAD_CAPE_SUCCESS')));

			}

		} else {
			new ForbiddenException();
		}
	}

	function profile() {
		if($this->isConnected) {

			$this->loadModel('User');

			$this->set('title_for_layout', $this->User->getKey('pseudo'));
			$this->layout= $this->Configuration->getKey('layout');
			if($this->EyPlugin->isInstalled('eywek.shop.1')) {
				$this->set('shop_active', true);
			} else {
				$this->set('shop_active', false);
			}

			$available_ranks = array(0 => $this->Lang->get('USER__RANK_MEMBER'), 2 => $this->Lang->get('USER__RANK_MODERATOR'), 3 => $this->Lang->get('USER__RANK_ADMINISTRATOR'), 4 => $this->Lang->get('USER__RANK_ADMINISTRATOR'), 5 => $this->Lang->get('USER__RANK_BANNED'));
			$this->loadModel('Rank');
			$custom_ranks = $this->Rank->find('all');
			foreach ($custom_ranks as $key => $value) {
				$available_ranks[$value['Rank']['rank_id']] = $value['Rank']['name'];
			}
			$this->set(compact('available_ranks'));

			$api = $this->API->getIp($this->User->getKey('pseudo'));
			$this->set(compact('api'));

			$this->set('can_cape', $this->API->can_cape());
			$this->set('can_skin', $this->API->can_skin());

			$this->loadModel('ApiConfiguration');
			$configAPI = $this->ApiConfiguration->find('first');
			$skin_width_max = $configAPI['ApiConfiguration']['skin_width'];
			$skin_height_max = $configAPI['ApiConfiguration']['skin_height'];
			$cape_width_max = $configAPI['ApiConfiguration']['cape_width'];
			$cape_height_max = $configAPI['ApiConfiguration']['cape_height'];

			$this->set(compact('skin_width_max', 'skin_height_max', 'cape_width_max', 'cape_height_max'));

			if($this->Configuration->getKey('confirm_mail_signup') && !empty($this->User->getKey('confirmed')) && date('Y-m-d H:i:s', strtotime($this->User->getKey('confirmed'))) != $this->User->getKey('confirmed')) { // si ca ne correspond pas à une date -> compte non confirmé
				$this->Session->setFlash($this->Lang->get('USER__MSG_NOT_CONFIRMED_EMAIL'), 'default.warning');
			}

		} else {
			$this->redirect('/');
		}
	}

	function change_pw() {
		$this->autoRender = false;
		if($this->isConnected) {
			if($this->request->is('ajax')) {
				if(!empty($this->request->data['password']) AND !empty($this->request->data['password_confirmation'])) {
					$password = $this->Util->password($this->request->data['password'], $this->User->getKey('pseudo'));
					$password_confirmation = $this->Util->password($this->request->data['password_confirmation'], $this->User->getKey('pseudo'));
					if($password == $password_confirmation) {

						$event = new CakeEvent('beforeUpdatePassword', $this, array('user' => $this->User->getAllFromCurrentUser(), 'new_password' => $this->request->data['password']));
						$this->getEventManager()->dispatch($event);
						if($event->isStopped()) {
							return $event->result;
						}

						$this->User->setKey('password', $password);
						echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__PASSWORD_UPDATE_SUCCESS')));
					} else {
						echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_PASSWORDS_NOT_SAME')));
					}
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_MUST_BE_LOGGED')));
		}
	}

	function change_email() {
		$this->autoRender = false;
		if($this->isConnected && $this->Permissions->can('EDIT_HIS_EMAIL')) {
			if($this->request->is('ajax')) {
				if(!empty($this->request->data['email']) AND !empty($this->request->data['email_confirmation'])) {
					if($this->request->data['email'] == $this->request->data['email_confirmation']) {
						if(filter_var($this->request->data['email'], FILTER_VALIDATE_EMAIL)) {

							$event = new CakeEvent('beforeUpdateEmail', $this, array('user' => $this->User->getAllFromCurrentUser(), 'new_email' => $this->request->data['email']));
							$this->getEventManager()->dispatch($event);
							if($event->isStopped()) {
								return $event->result;
							}

							$this->User->setKey('email', $this->request->data['email']);
							echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__EMAIL_UPDATE_SUCCESS')));
						} else {
							echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_EMAIL_NOT_VALID')));
						}
					} else {
						echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_EMAIL_NOT_SAME')));
					}
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
			}
		} else {
			throw new ForbiddenException();
		}
	}

	function send_points() {
		$this->autoRender = false;
		if($this->isConnected) {
			if($this->request->is('ajax')) {
				if(!empty($this->request->data['to']) AND !empty($this->request->data['how'])) {
					if($this->User->exist($this->request->data['to'])) {
						$how = intval($this->request->data['how']);
						if($how > 0) {
							$money_user = $this->User->getKey('money') - $how;
							if($money_user >= 0) {

								$event = new CakeEvent('beforeSendPoints', $this, array('user' => $this->User->getAllFromCurrentUser(), 'new_user_sold' => $money_user, 'to' => $this->request->data['to'], 'how' => $this->request->data['how']));
								$this->getEventManager()->dispatch($event);
								if($event->isStopped()) {
									return $event->result;
								}

								$this->User->setKey('money', $money_user);
								$to_money = $this->User->getFromUser('money', $this->request->data['to']) + $how;
								$this->User->setToUser('money', $to_money, $this->request->data['to']);
								$this->History->set('SEND_MONEY', 'shop', $this->request->data['to'].'|'.$how);
								echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('SHOP__USER_POINTS_TRANSFER_SUCCESS')));
							} else {
								echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__BUY_ERROR_NO_ENOUGH_MONEY')));
							}
						} else {
							echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('SHOP__USER_POINTS_TRANSFER_ERROR_EMPTY')));
						}
					} else {
						echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_NOT_FOUND')));
					}
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__BAD_REQUEST')));
			}
		} else {
			echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('USER__ERROR_MUST_BE_LOGGED')));
		}
	}

	function admin_index() {
		if($this->isConnected AND $this->User->isAdmin()) {

			$this->set('title_for_layout',$this->Lang->get('USER__TITLE'));
			$this->layout = 'admin';

			$this->set('type', $this->Configuration->getKey('member_page_type'));

		} else {
			$this->redirect('/');
		}
	}

	function admin_liveSearch($query = false) {
		$this->autoRender = false;
		$this->response->type('json');
		if($this->isConnected AND $this->User->isAdmin()) {
			if($query != false) {

				$result = $this->User->find('all', array('conditions' => array('pseudo LIKE' => $query.'%')));


				foreach ($result as $key => $value) {

					$users[] = array('pseudo' => $value['User']['pseudo'], 'id' => $value['User']['id']);

				}

				echo (empty($result)) ? json_encode(array('status' => false)) : json_encode(array('status' => true, 'data' => $users));

			} else {
				echo json_encode(array('status' => false));
			}
		} else {
			echo json_encode(array('status' => false));
		}
	}

	public function admin_get_users() {
		if($this->isConnected AND $this->User->isAdmin()) {
			$this->autoRender = false;
			$this->response->type('json');

			if($this->request->is('ajax')) {

				$available_ranks = array(
					0 => array('label' => 'success', 'name' => $this->Lang->get('USER__RANK_MEMBER')),
					2 => array('label' => 'warning', 'name' => $this->Lang->get('USER__RANK_MODERATOR')),
					3 => array('label' => 'danger', 'name' => $this->Lang->get('USER__RANK_ADMINISTRATOR')),
					4 => array('label' => 'danger', 'name' => $this->Lang->get('USER__RANK_ADMINISTRATOR')),
					5 => array('label' => 'primary', 'name' => $this->Lang->get('USER__RANK_BANNED'))
				);
				$this->loadModel('Rank');
				$custom_ranks = $this->Rank->find('all');
				foreach ($custom_ranks as $key => $value) {
					$available_ranks[$value['Rank']['rank_id']] = array('label' => 'info', 'name' => $value['Rank']['name']);
				}

				$find = $this->User->find('all');

				$data['data'] = array();

				foreach ($find as $key => $value) {

					$username = $value['User']['pseudo'];
					$date = 'Le'.$this->Lang->date($value['User']['created']);
					$rank = '<span class="label label-'.$available_ranks[$value['User']['rank']]['label'].'">'.$available_ranks[$value['User']['rank']]['name'].'</span>';
					$btns = '<a href="'.Router::url(array('controller' => 'user', 'action' => 'edit/'.$value["User"]["id"], 'admin' => true)).'" class="btn btn-info">'.$this->Lang->get('GLOBAL__EDIT').'</a>';
					$btns .= '&nbsp;<a onClick="confirmDel('.Router::url(array('controller' => 'user', 'action' => 'delete/'.$value["User"]["id"], 'admin' => true)).')" class="btn btn-danger">'.$this->Lang->get('GLOBAL__DELETE').'</button>';

					$data['data'][] = array($username, $date, $rank, $btns);

				}

				echo json_encode($data);

			}
		}
	}

	function admin_edit($id = false) {
		if($this->isConnected AND $this->User->isAdmin()) {
			if($id != false) {

				$this->layout = 'admin';
				$this->set('title_for_layout',$this->Lang->get('USER__EDIT_TITLE'));
				$this->loadModel('User');
				$find = $this->User->find('all', array('conditions' => array('id' => $id)));

				if(!empty($find)) {
					$search_user = $find[0]['User'];
					$this->loadModel('History');
					$findHistory = $this->History->getLastFromUser($id);
					$search_user['History'] = $this->History->format($findHistory);

					$options_ranks = array(
						0 => $this->Lang->get('USER__RANK_MEMBER'),
						2 => $this->Lang->get('USER__RANK_MODERATOR'),
						3 => $this->Lang->get('USER__RANK_ADMINISTRATOR'),
						4 => $this->Lang->get('USER__RANK_SUPER_ADMINISTRATOR'),
						5 => $this->Lang->get('USER__RANK_BANNED')
					);
					$this->loadModel('Rank');
					$custom_ranks = $this->Rank->find('all');
					foreach ($custom_ranks as $key => $value) {
						$options_ranks[$value['Rank']['rank_id']] = $value['Rank']['name'];
					}

					if($this->Configuration->getKey('confirm_mail_signup') && !empty($search_user['confirmed']) && date('Y-m-d H:i:s', strtotime($user['confirmed'])) != $user['confirmed']) {
						$search_user['confirmed'] = false;
					} else {
						$search_user['confirmed'] = true;
					}

					$this->set(compact('options_ranks'));

					$this->set(compact('search_user'));
				} else {
					$this->Session->setFlash($this->Lang->get('UNKNOWN_ID'), 'default.error');
					$this->redirect(array('controller' => 'user', 'action' => 'index', 'admin' => true));
				}
			} else {
				$this->redirect(array('controller' => 'user', 'action' => 'index', 'admin' => true));
			}
		} else {
			$this->redirect('/');
		}
	}

	function admin_confirm($user_id = false) {
		$this->autoRender = false;
		if(isset($user_id) && $this->isConnected AND $this->User->isAdmin()) {

			$find = $this->User->find('first', array('conditions' => array('id' => $user_id)));

			if(!empty($find)) {

				$event = new CakeEvent('beforeConfirmAccount', $this, array('user_id' => $find['User']['id'], 'manual' => true));
				$this->getEventManager()->dispatch($event);
				if($event->isStopped()) {
					return $event->result;
				}

				$this->User->read(null, $find['User']['id']);
				$this->User->set(array('confirmed' => date('Y-m-d H:i:s')));
				$this->User->save();

				$userSession = $find['User']['id'];

				$this->redirect(array('action' => 'edit', $user_id));

			} else {
				throw new NotFoundException();
			}

		} else {
			throw new NotFoundException();
		}
	}

	function admin_edit_ajax() {
		$this->autoRender = false;
		if($this->isConnected && $this->User->isAdmin()) {
			if($this->request->is('post')) {
				$this->loadModel('User');
				if(!empty($this->request->data['id']) && !empty($this->request->data['email']) && !empty($this->request->data['rank'])) {

					$findUser = $this->User->find('first', array('conditions' => array('id' => intval($this->request->data['id']))));

					if(empty($findUser)) {
						echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__EDIT_ERROR_UNKNOWN')));
						exit;
					}

					if($findUser['User']['id'] == $this->User->getKey('id') && $this->request->data['rank'] != $this->User->getKey('rank')) {
						echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__EDIT_ERROR_YOURSELF')));
						exit;
					}

					$data = array(
						'email' => $this->request->data['email'],
						'rank' => $this->request->data['rank']
					);

					if(!empty($this->request->data['password'])) {
						$data['password'] = $this->Util->password($this->request->data['password'], $findUser['User']['pseudo']);
						$password_updated = true;
					} else {
						$password_updated = false;
					}

					if($this->EyPlugin->isInstalled('eywek.shop.1')) {
						$data['money'] = $this->request->data['money'];
					}

					if($this->EyPlugin->isInstalled('eywek.vote.3')) {
						$data['vote'] = $this->request->data['vote'];
					}

					$event = new CakeEvent('beforeEditUser', $this, array('user_id' => $findUser['User']['id'], 'data' => $data, 'password_updated' => $password_updated));
					$this->getEventManager()->dispatch($event);
					if($event->isStopped()) {
						return $event->result;
					}

					$this->User->read(null, $findUser['User']['id']);
					$this->User->set($data);
					$this->User->save();

					$this->History->set('EDIT_USER', 'user');
					$this->Session->setFlash($this->Lang->get('USER__EDIT_SUCCESS'), 'default.success');
					echo json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__EDIT_SUCCESS')));
				} else {
					echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')));
				}
			} else {
				echo json_encode(array('statut' => false, 'msg' => $this->Lang->get('NOT_POST' ,$language)));
			}
		} else {
			$this->redirect('/');
		}
	}

	function admin_delete($id = false) {
		$this->autoRender = false;
		if($this->isConnected AND $this->User->isAdmin()) {
			if($id != false) {
				$this->loadModel('User');
				$find = $this->User->find('all', array('conditions' => array('id' => $id)));
				if(!empty($find)) {

					$event = new CakeEvent('beforeDeleteUser', $this, array('user' => $find['User']));
					$this->getEventManager()->dispatch($event);
					if($event->isStopped()) {
						return $event->result;
					}

					$this->User->delete($id);
					$this->History->set('DELETE_USER', 'user');
					$this->Session->setFlash($this->Lang->get('USER__DELETE_SUCCESS'), 'default.success');
					$this->redirect(array('controller' => 'user', 'action' => 'index', 'admin' => true));
				} else {
					$this->Session->setFlash($this->Lang->get('UNKNONW_ID'), 'default.error');
					$this->redirect(array('controller' => 'user', 'action' => 'index', 'admin' => true));
				}
			} else {
				$this->redirect(array('controller' => 'user', 'action' => 'index', 'admin' => true));
			}
		} else {
			$this->redirect('/');
		}
	}

}
