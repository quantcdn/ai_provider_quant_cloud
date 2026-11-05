<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Accepts the stored OAuth tokens as this site's own.
 *
 * This is a separate confirm form, not a button on the settings form:
 * config_readonly locks config forms, and this action writes no config.
 * The route needs the restricted 'confirm quant cloud same site'
 * permission, and the Form API token protects it against CSRF.
 */
class KeepConnectionForm extends ConfirmFormBase {

  /**
   * The auth service.
   */
  protected AuthService $authService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->authService = $container->get('ai_provider_quant_cloud.auth');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_quant_cloud_keep_connection';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Is this the original site that connected to Quant Cloud?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $differences = $this->authService->getFingerprintDifferences();
    return new TranslatableMarkup('Only confirm if this is the original site. A copy that confirms will log the original out of Quant Cloud. Changed since the connection: @components.', [
      '@components' => $differences ? implode(', ', $differences) : $this->t('nothing'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('This is the same site — keep the connection');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('ai_provider_quant_cloud.settings_form');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->authService->confirmSameSite();
    $this->messenger()->addStatus($this->t('The Quant Cloud connection is kept for this site.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
