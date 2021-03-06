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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Newscoop\SendFeedbackBundle\Form\Type\SettingsType;

class AdminController extends Controller
{
    /**
    * @Route("/admin/send-feedback")
    * @Template()
    */
    public function indexAction(Request $request)
    {   
        $preferencesService = $this->container->get('system_preferences_service');
        $translator = $this->container->get('translator');
        $form = $this->container->get('form.factory')->create(new SettingsType(), array(
            'toEmail' => $preferencesService->SendFeedbackEmail,
            'allowNonUsers' => $preferencesService->AllowFeedbackFromNonUsers,
        ), array());
        
        if ($request->isMethod('POST')) {
            $form->bind($request);
            if ($form->isValid()) {
                $data = $form->getData();

                $preferencesService->set('SendFeedbackEmail', $data['toEmail']);
                $preferencesService->set('AllowFeedbackFromNonUsers', $data['allowNonUsers']);
                $this->get('session')->getFlashBag()->add('success', $translator->trans('plugin.feedback.msg.success'));

                return $this->redirect($this->generateUrl('newscoop_sendfeedback_admin_index'));
                
            }
            
            $this->get('session')->getFlashBag()->add('error', $translator->trans('plugin.feedback.msg.error'));

            return $this->redirect($this->generateUrl('newscoop_sendfeedback_admin_index'));
            
        }

        return array(
            'form' => $form->createView()
        );
    }
}
