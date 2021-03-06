<?php
/**
 * @package Newscoop\SendFeedbackBundle
 * @author Rafał Muszyński <rafal.muszynski@sourcefabric.org>
 * @copyright 2013 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\SendFeedbackBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Newscoop\SendFeedbackBundle\Form\Type\SendFeedbackType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Newscoop\Entity\Feedback;
use Newscoop\EventDispatcher\Events\GenericEvent;

/**
 * Send feedback controller
 */
class SendFeedbackController extends Controller
{
    /**
    * @Route("/plugin/send-feedback")
    * @Method("POST")
    */
    public function indexAction(Request $request)
    {
        $translator = $this->container->get('translator');
        $em = $this->container->get('em');
        $attachmentService = $this->container->get('attachment');
        $preferencesService = $this->container->get('system_preferences_service');
        $feedbackRepository = $em->getRepository('Newscoop\Entity\Feedback');
        $to = $preferencesService->SendFeedbackEmail;
        $allowNonUsers = $preferencesService->AllowFeedbackFromNonUsers;
        $defaultFrom = $preferencesService->EmailFromAddress;
        $response = array();
        $parameters = $request->request->all();
        $form = $this->container->get('form.factory')->create(new SendFeedbackType(), array(), array());

        if ($request->isMethod('POST')) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();
                $user = $this->container->get('user')->getCurrentUser();
                $attachment = $form['attachment']->getData();
                $date = new \DateTime("now");
                if (is_null($data['subject']) || is_null($data['message'])) {
                    $response['response'] = array(
                        'status' => false,
                        'message' => $translator->trans('plugin.feedback.msg.notfilled'),
                        'post-subject' => $request->request->get('subject'),
                        'post-message' => $request->request->get('message')
                    );

                    return new JsonResponse($response);
                }

                if ($user) {
                    $acceptanceRepository = $em->getRepository('Newscoop\Entity\Comment\Acceptance');
                    if (isset($parameters['publication'])) {
                    	if ($acceptanceRepository->checkParamsBanned($user->getUsername(), $user->getEmail(), null, $parameters['publication'])) {
                            $response['response'] = $translator->trans('plugin.feedback.msg.banned');
                    	}
                    }
                } else {
                    if (!$allowNonUsers) {
                        $response['response'] = array(
                            'status' => false,
                            'message' => $translator->trans('plugin.feedback.msg.errorlogged')
                        );

                        return new JsonResponse($response);
                    } else {
                        $emailService = $this->container->get('email');
                        $emailService->send($data['subject'], $data['message'], $to, $defaultFrom);

                         $response['response'] = array(
                            'status' => true,
                            'message' => $translator->trans('plugin.feedback.msg.success')
                        );

                        return new JsonResponse($response);
                    }
                }

                if (empty($response['response'])) {
                    $feedback = new Feedback();

                    $values = array(
                        'user' => $user,
                        'publication' => isset($parameters['publication']) ? $parameters['publication'] : null,
                        'section' => isset($parameters['section']) ? $parameters['section'] : null,
                        'article' => isset($parameters['article']) ? $parameters['article'] : null,
                        'subject' => $data['subject'],
                        'message' => $data['message'],
                        'url' => isset($parameters['feedbackUrl']) ? $parameters['feedbackUrl'] : null,
                        'time_created' => new \DateTime(),
                        'language' => isset($parameters['language']) ? $parameters['language'] : null,
                        'status' => 'pending',
                        'attachment_type' => 'none',
                        'attachment_id' => 0
                    );

                    if ($attachment) {
                        if ($attachment->getClientSize() <= $attachment->getMaxFilesize() && $attachment->getClientSize() != 0) {
                            if (in_array($attachment->guessClientExtension(), array('png','jpg','jpeg','gif','pdf'))) {
                                $response['response'] = $this->processAttachment($attachment, $user, $values);
                            } else {
                                $response['response'] = array(
                                    'status' => false,
                                    'message' => $translator->trans('plugin.feedback.msg.errorfile')
                                );

                                return new JsonResponse($response);
                            }
                        } else {
                            $response['response'] = array(
                                'status' => false,
                                'message' => $translator->trans('plugin.feedback.msg.errorsize', array('%size%' => $preferencesService->MaxUploadFileSize))
                            );
                        }
                    } else {
			if (isset($parameters['publication'])) {
                            $feedbackRepository->save($feedback, $values);
                            $feedbackRepository->flush();
			}
                        $this->sendMail($values, $user, $to, $attachment);

                        $response['response'] = array(
                            'status' => true,
                            'message' => $translator->trans('plugin.feedback.msg.success')
                        );
                    }
                }

            } else {
                $response['response'] = array(
                    'status' => false,
                    'message' => 'Invalid Form' 
                );
	    }

            return new JsonResponse($response);
        }
    }

    /**
     * Send e-mail message with feedback
     *
     * @param array                $values Values
     * @param Newscoop\Entity\User $user   User
     * @param string               $to     Email that messages will be sent
     * @param UploadedFile|null    $file   Uploaded file dir
     *
     * @return void
     */
    private function sendMail($values, $user, $to, $file = null)
    {
        $translator = $this->container->get('translator');
        $emailService = $this->container->get('email');
        $attachmentService = $this->container->get('attachment');
        $imageService = $this->container->get('image');
        $zendRouter = $this->container->get('zend_router');
        $request = $this->container->get('request');
        $link = $request->getScheme() . '://' . $request->getHttpHost();

        $message = $this->renderView(
            'NewscoopSendFeedbackBundle::email.txt.twig',
            array(
                'userMessage' => $values['message'],
                'from' => $translator->trans('plugin.feedback.email.from', array(
                    '%userLink%' => $link . $zendRouter->assemble(array('controller' => 'user', 'action' => 'profile', 'module' => 'default')) ."/". urlencode($user->getUsername())
                )),
                'send' => $translator->trans('plugin.feedback.email.send', array(
                    '%siteLink%' => $values['url'],
                ))
            )
        );

        $subject = $translator->trans('plugin.feedback.email.subject', array('%subject%' => $values['subject']));
        $attachmentDir = '';
        if ($file instanceof \Newscoop\Image\LocalImage) {
            $attachmentDir = $imageService->getImagePath().$file->getBasename();
        }

        if ($file instanceof \Newscoop\Entity\Attachment) {
            $attachmentDir = $attachmentService->getStorageLocation($file);
        }

        $emailService->send($subject, $message, $to, $user->getEmail(), $attachmentDir);
    }

    /**
     * Process attachment
     *
     * @param UploadedFile         $attachment Uploaded file
     * @param Newscoop\Entity\User $user       User
     * @param array                $values     Values
     *
     * @return array
     */
    private function processAttachment($attachment, $user, $values)
    {
        $imageService = $this->container->get('image');
        $attachmentService = $this->container->get('attachment');
        $em = $this->container->get('em');
        $translator = $this->container->get('translator');
        $preferencesService = $this->container->get('system_preferences_service');
        $feedbackRepository = $em->getRepository('Newscoop\Entity\Feedback');
        $language = $em->getRepository('Newscoop\Entity\Language')->findOneById($values['language']);
        $toEmail = $preferencesService->SendFeedbackEmail;
        $feedback = new Feedback();
        $source = array(
            'user' => $user,
            'source' => 'feedback'
        );

        if (strstr($attachment->getClientMimeType(), 'image')) {
            $image = $imageService->upload($attachment, $source);
            $values['attachment_type'] = 'image';
            $values['attachment_id'] = $image->getId();
            $feedbackRepository->save($feedback, $values);
            $feedbackRepository->flush();

            $this->sendMail($values, $user, $toEmail, $image);

            $this->get('dispatcher')
                ->dispatch('image.delivered', new GenericEvent($this, array(
                    'user' => $user,
                    'image_id' => $image->getId()
                )));

            return array(
                'status' => true,
                'message' => $translator->trans('plugin.feedback.msg.successimage')
            );
        }

        $file = $attachmentService->upload($attachment, '', $language, $source);
        $values['attachment_type'] = 'document';
        $values['attachment_id'] = $file->getId();
        $feedbackRepository->save($feedback, $values);
        $feedbackRepository->flush();
        $this->sendMail($values, $user, $toEmail, $file);

        $this->get('dispatcher')
            ->dispatch('document.delivered', new GenericEvent($this, array(
                'user' => $user,
                'document_id' => $file->getId()
            )));

        return array(
            'status' => true,
            'message' => $translator->trans('plugin.feedback.msg.successfile')
        );
    }
}
