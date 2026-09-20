<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Plugin.php';

class MomoBirdAI_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $this->user->pass('administrator');
        $this->security->protect();

        try {
            $controller = $this->controller();
            $operation = (string) $this->request->get('op');
            $input = array(
                'run_token' => $this->request->get('run_token'),
                'cursor' => $this->request->get('cursor'),
                'limit' => $this->request->get('limit')
            );
            $this->response->throwJson($controller->dispatch($operation, $input));
        } catch (InvalidArgumentException $error) {
            $this->respondError(400, 'validation_error', $error);
        } catch (MomoBirdAI_HttpException $error) {
            $status = in_array($error->status(), array(401, 403, 404, 409), true) ? $error->status() : 502;
            $this->respondError($status, $error->apiCode(), $error);
        } catch (Throwable $error) {
            $this->respondError(500, 'sync_error', $error);
        }
    }

    private function controller()
    {
        $settings = MomoBirdAI_Plugin::settings();
        if ((string) $settings['enabled'] !== '1') {
            throw new InvalidArgumentException('MomoBird 自动同步尚未启用');
        }
        $config = MomoBirdAI_Config::fromArray($settings);
        $db = Typecho_Db::get();
        $options = Helper::options();
        $client = new MomoBirdAI_HttpClient($config);
        $state = new MomoBirdAI_SyncRepository($db, $settings['site_id']);
        $posts = new MomoBirdAI_PostRepository($db, $options);
        $sync = new MomoBirdAI_SyncService($client, $state, $settings['site_id']);
        $full = new MomoBirdAI_FullSyncRun($posts, $sync, $state, $client, $settings['site_id']);
        return new MomoBirdAI_AdminController($client, $posts, $state, $sync, $full);
    }

    private function respondError($status, $code, $error)
    {
        $this->response->setStatus((int) $status);
        $message = mb_substr(strip_tags((string) $error->getMessage()), 0, 180, 'UTF-8');
        $this->response->throwJson(array(
            'ok' => false,
            'error' => array('code' => (string) $code, 'message' => $message)
        ));
    }
}
