<?php

namespace App\Controller;

use App\Service\AppDatabase;
use App\Service\AccessControl;
use App\Service\CompanyProfile;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/index.html.twig', $db->dashboardData() + ['user' => $user]);
    }

    #[Route('/products', name: 'app_products')]
    public function products(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/products.html.twig', $db->dashboardData($request->query->all()) + [
            'user' => $user,
            'filters' => $request->query->all(),
        ]);
    }

    #[Route('/products/new', name: 'app_product_new', methods: ['POST'])]
    public function newProduct(Request $request, AppDatabase $db): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        try {
            $db->saveProduct($request->request->all(), (int) $user['id']);
            $this->addFlash('success', 'ØªÙ… Ø­ÙØ¸ Ø§Ù„Ù…Ù†ØªØ¬');
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }

        return $this->redirectToRoute('app_products');
    }

    #[Route('/products/{id}', name: 'app_product_show', requirements: ['id' => '\d+'])]
    public function productShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $product = $db->product($id);
        if (!$product) {
            throw $this->createNotFoundException();
        }

        return $this->render('app/product_show.html.twig', [
            'user' => $user,
            'product' => $product,
            'movements' => $db->productMovements($id),
        ]);
    }

    #[Route('/products/{id}/edit', name: 'app_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function productEdit(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $product = $db->product($id);
        if (!$product) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            try {
                $db->saveProduct($request->request->all(), (int) $user['id'], $id);
                $this->addFlash('success', 'ØªÙ… ØªØ­Ø¯ÙŠØ« Ø§Ù„Ù…Ù†ØªØ¬');
                return $this->redirectToRoute('app_product_show', ['id' => $id]);
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
        }

        return $this->render('app/product_edit.html.twig', [
            'user' => $user,
            'product' => $product,
            'categories' => $db->categories(false),
        ]);
    }

    #[Route('/products/{id}/deactivate', name: 'app_product_deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function productDeactivate(int $id, Request $request, AppDatabase $db, AccessControl $access): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if (!$access->can('delete', $user)) {
            $this->addFlash('error', 'ليست لديك صلاحية التعطيل');
            return $this->redirectToRoute('app_products');
        }
        $db->deactivateProduct($id);
        $this->addFlash('success', 'ØªÙ… ØªØ¹Ø·ÙŠÙ„ Ø§Ù„Ù…Ù†ØªØ¬');
        return $this->redirectToRoute('app_products');
    }

    #[Route('/stock', name: 'app_stock')]
    public function stock(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/stock.html.twig', $db->dashboardData() + ['user' => $user]);
    }

    #[Route('/stock/in', name: 'app_stock_in', methods: ['POST'])]
    public function stockIn(Request $request, AppDatabase $db): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        try {
            $db->addStock(
                (int) $request->request->get('product_id'),
                (int) $request->request->get('quantity'),
                (string) $request->request->get('note'),
                (int) $user['id'],
                (int) $request->request->get('supplier_id') ?: null,
                $request->request->get('unit_cost') !== '' ? (float) $request->request->get('unit_cost') : null
            );
            $this->addFlash('success', 'ØªÙ… ØªØ­Ø¯ÙŠØ« Ø§Ù„Ù…Ø®Ø²ÙˆÙ†');
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }

        return $this->redirectToRoute('app_stock');
    }

    #[Route('/operations', name: 'app_operations')]
    public function operations(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/operations.html.twig', $db->dashboardData() + ['user' => $user]);
    }

    #[Route('/operations/new', name: 'app_operation_new', methods: ['POST'])]
    public function newOperation(Request $request, AppDatabase $db): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        try {
            $id = $db->createOperation($request->request->all(), (int) $user['id']);
            return $this->redirectToRoute('app_invoice', ['id' => $id]);
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
            return $this->redirectToRoute('app_operations');
        }
    }

    #[Route('/billing', name: 'app_billing')]
    public function billing(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return $this->render('app/billing.html.twig', $db->dashboardData() + ['user' => $user]);
    }

    #[Route('/categories', name: 'app_categories', methods: ['GET', 'POST'])]
    public function categories(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if ($request->isMethod('POST')) {
            try {
                $db->saveCategory($request->request->all());
                $this->addFlash('success', 'ØªÙ… Ø­ÙØ¸ Ø§Ù„ØµÙ†Ù');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
            return $this->redirectToRoute('app_categories');
        }

        return $this->render('app/categories.html.twig', ['user' => $user, 'categories' => $db->categories(false)]);
    }

    #[Route('/categories/{id}/edit', name: 'app_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function categoryEdit(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $category = $db->category($id);
        if (!$category) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveCategory($request->request->all(), $id);
                $this->addFlash('success', 'ØªÙ… ØªØ­Ø¯ÙŠØ« Ø§Ù„ØµÙ†Ù');
                return $this->redirectToRoute('app_categories');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
        }
        return $this->render('app/category_edit.html.twig', ['user' => $user, 'category' => $category]);
    }

    #[Route('/categories/{id}', name: 'app_category_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function categoryShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $category = $db->category($id);
        if (!$category) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/category_show.html.twig', [
            'user' => $user,
            'category' => $category,
            'products' => $db->categoryProducts($id),
        ]);
    }

    #[Route('/categories/{id}/deactivate', name: 'app_category_deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function categoryDeactivate(int $id, Request $request, AppDatabase $db, AccessControl $access): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if (!$access->can('delete', $user)) {
            $this->addFlash('error', 'ليست لديك صلاحية التعطيل');
            return $this->redirectToRoute('app_categories');
        }
        $db->deactivateCategory($id);
        return $this->redirectToRoute('app_categories');
    }

    #[Route('/suppliers', name: 'app_suppliers', methods: ['GET', 'POST'])]
    public function suppliers(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveSupplier($request->request->all());
                $this->addFlash('success', 'ØªÙ… Ø­ÙØ¸ Ø§Ù„Ù…ÙˆØ±Ø¯');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
            return $this->redirectToRoute('app_suppliers');
        }
        return $this->render('app/suppliers.html.twig', ['user' => $user, 'suppliers' => $db->suppliers(false)]);
    }

    #[Route('/suppliers/{id}/edit', name: 'app_supplier_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function supplierEdit(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $supplier = $db->supplier($id);
        if (!$supplier) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveSupplier($request->request->all(), $id);
                return $this->redirectToRoute('app_suppliers');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
        }
        return $this->render('app/supplier_edit.html.twig', ['user' => $user, 'supplier' => $supplier]);
    }

    #[Route('/suppliers/{id}', name: 'app_supplier_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function supplierShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $supplier = $db->supplier($id);
        if (!$supplier) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/supplier_show.html.twig', [
            'user' => $user,
            'supplier' => $supplier,
            'movements' => $db->supplierMovements($id),
        ]);
    }

    #[Route('/suppliers/{id}/deactivate', name: 'app_supplier_deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supplierDeactivate(int $id, Request $request, AppDatabase $db, AccessControl $access): RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if (!$access->can('delete', $user)) {
            $this->addFlash('error', 'ليست لديك صلاحية التعطيل');
            return $this->redirectToRoute('app_suppliers');
        }
        $db->deactivateSupplier($id);
        return $this->redirectToRoute('app_suppliers');
    }

    #[Route('/clients', name: 'app_clients', methods: ['GET', 'POST'])]
    public function clients(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveClient($request->request->all());
                $this->addFlash('success', 'ØªÙ… Ø­ÙØ¸ Ø§Ù„Ø¹Ù…ÙŠÙ„');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
            return $this->redirectToRoute('app_clients');
        }
        return $this->render('app/clients.html.twig', ['user' => $user, 'clients' => $db->clients()]);
    }

    #[Route('/clients/{id}/edit', name: 'app_client_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function clientEdit(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $client = $db->client($id);
        if (!$client) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveClient($request->request->all(), $id);
                return $this->redirectToRoute('app_clients');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
        }
        return $this->render('app/client_edit.html.twig', ['user' => $user, 'client' => $client]);
    }

    #[Route('/clients/{id}', name: 'app_client_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function clientShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $client = $db->client($id);
        if (!$client) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/client_show.html.twig', [
            'user' => $user,
            'client' => $client,
            'vehicles' => $db->clientVehicles($id),
            'operations' => $db->clientOperations($id),
        ]);
    }

    #[Route('/vehicles', name: 'app_vehicles', methods: ['GET', 'POST'])]
    public function vehicles(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if ($request->isMethod('POST')) {
            try {
                $db->saveVehicle($request->request->all());
                $this->addFlash('success', 'ØªÙ… Ø­ÙØ¸ Ø§Ù„Ø³ÙŠØ§Ø±Ø©');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
            return $this->redirectToRoute('app_vehicles');
        }
        return $this->render('app/vehicles.html.twig', [
            'user' => $user,
            'clients' => $db->clients(),
            'brands' => $db->vehicleBrands(),
            'models' => $db->vehicleModels(),
            'vehicles' => $db->vehicles(),
            'selected_client_id' => (int) $request->query->get('client', 0),
        ]);
    }

    #[Route('/vehicles/{id}', name: 'app_vehicle_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vehicleShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $vehicle = $db->vehicle($id);
        if (!$vehicle) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/vehicle_show.html.twig', [
            'user' => $user,
            'vehicle' => $vehicle,
            'operations' => $db->vehicleOperations($id),
        ]);
    }

    #[Route('/vehicles/settings', name: 'app_vehicle_settings', methods: ['GET', 'POST'])]
    public function vehicleSettings(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if ($request->isMethod('POST')) {
            try {
                if ($request->request->get('kind') === 'brand') {
                    $db->saveVehicleBrand((string) $request->request->get('name'));
                } else {
                    $db->saveVehicleModel((int) $request->request->get('brand_id'), (string) $request->request->get('name'));
                }
                $this->addFlash('success', 'ØªÙ… Ø§Ù„Ø­ÙØ¸');
            } catch (Throwable $e) {
                $this->addFlash('error', $this->safeMessage($e));
            }
            return $this->redirectToRoute('app_vehicle_settings');
        }
        return $this->render('app/vehicle_settings.html.twig', ['user' => $user, 'brands' => $db->vehicleBrands(), 'models' => $db->vehicleModels()]);
    }

    #[Route('/vehicle-brands/{id}', name: 'app_vehicle_brand_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vehicleBrandShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $brand = $db->vehicleBrand($id);
        if (!$brand) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/vehicle_brand_show.html.twig', [
            'user' => $user,
            'brand' => $brand,
            'models' => $db->brandModels($id),
        ]);
    }

    #[Route('/vehicle-models/{id}', name: 'app_vehicle_model_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vehicleModelShow(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $model = $db->vehicleModel($id);
        if (!$model) {
            throw $this->createNotFoundException();
        }
        return $this->render('app/vehicle_model_show.html.twig', [
            'user' => $user,
            'model' => $model,
        ]);
    }

    #[Route('/invoice/{id}', name: 'app_invoice')]
    public function invoice(int $id, Request $request, AppDatabase $db, CompanyProfile $company): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $operation = $db->operation($id);
        if (!$operation) {
            throw $this->createNotFoundException();
        }
        return $this->render('documents/invoice.html.twig', ['operation' => $operation, 'user' => $user, 'company' => $company->data()]);
    }

    #[Route('/receipt/{id}', name: 'app_receipt')]
    public function receipt(int $id, Request $request, AppDatabase $db, CompanyProfile $company): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        $operation = $db->operation($id);
        if (!$operation) {
            throw $this->createNotFoundException();
        }
        return $this->render('documents/receipt.html.twig', ['operation' => $operation, 'user' => $user, 'company' => $company->data()]);
    }

    #[Route('/users', name: 'app_users')]
    public function users(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireAdmin($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        return $this->render('app/users.html.twig', [
            'user' => $user,
            'users' => $db->users(),
            'toggle_token' => $this->csrfToken($request, 'users_toggle'),
        ]);
    }

    #[Route('/users/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function userNew(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireAdmin($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $form = ['role' => 'manager', 'active' => 1];
        $error = null;
        if ($request->isMethod('POST')) {
            $form = $request->request->all();
            try {
                $this->verifyCsrf($request, 'user_new');
                $db->createUser($form);
                $this->addFlash('success', 'تم إنشاء المستخدم');
                return $this->redirectToRoute('app_users');
            } catch (Throwable $e) {
                $error = $this->safeMessage($e);
            }
        }

        return $this->render('app/user_form.html.twig', [
            'user' => $user,
            'form' => $form,
            'error' => $error,
            'mode' => 'new',
            'token' => $this->csrfToken($request, 'user_new'),
        ]);
    }

    #[Route('/users/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function userEdit(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireAdmin($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $target = $db->userById($id);
        if (!$target) {
            throw $this->createNotFoundException();
        }

        $form = $target;
        $error = null;
        if ($request->isMethod('POST')) {
            $form = $request->request->all();
            try {
                $this->verifyCsrf($request, 'user_edit_' . $id);
                $db->updateUser($id, $form, (int) $user['id']);
                $this->addFlash('success', 'تم تحديث المستخدم');
                return $this->redirectToRoute('app_users');
            } catch (Throwable $e) {
                $error = $this->safeMessage($e);
            }
        }

        return $this->render('app/user_form.html.twig', [
            'user' => $user,
            'target' => $target,
            'form' => $form,
            'error' => $error,
            'mode' => 'edit',
            'token' => $this->csrfToken($request, 'user_edit_' . $id),
        ]);
    }

    #[Route('/users/{id}/password', name: 'app_user_password', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function userPassword(int $id, Request $request, AppDatabase $db): Response
    {
        $user = $this->requireAdmin($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $target = $db->userById($id);
        if (!$target) {
            throw $this->createNotFoundException();
        }

        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->verifyCsrf($request, 'user_password_' . $id);
                $db->changeUserPassword(
                    $id,
                    (string) $request->request->get('password', ''),
                    (string) $request->request->get('password_confirm', '')
                );
                $this->addFlash('success', 'تم تغيير كلمة المرور');
                return $this->redirectToRoute('app_users');
            } catch (Throwable $e) {
                $error = $this->safeMessage($e);
            }
        }

        return $this->render('app/user_password.html.twig', [
            'user' => $user,
            'target' => $target,
            'self_change' => false,
            'error' => $error,
            'token' => $this->csrfToken($request, 'user_password_' . $id),
        ]);
    }

    #[Route('/users/{id}/toggle', name: 'app_user_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userToggle(int $id, Request $request, AppDatabase $db): RedirectResponse
    {
        $user = $this->requireAdmin($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        try {
            $this->verifyCsrf($request, 'users_toggle');
            $db->toggleUser($id, (int) $user['id']);
            $this->addFlash('success', 'تم تحديث حالة المستخدم');
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }

        return $this->redirectToRoute('app_users');
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['GET', 'POST'])]
    public function profilePassword(Request $request, AppDatabase $db): Response
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->verifyCsrf($request, 'profile_password');
                $db->changeOwnPassword(
                    (int) $user['id'],
                    (string) $request->request->get('old_password', ''),
                    (string) $request->request->get('password', ''),
                    (string) $request->request->get('password_confirm', '')
                );
                $this->addFlash('success', 'تم تغيير كلمة المرور');
                return $this->redirectToRoute('app_dashboard');
            } catch (Throwable $e) {
                $error = $this->safeMessage($e);
            }
        }

        return $this->render('app/user_password.html.twig', [
            'user' => $user,
            'target' => $user,
            'self_change' => true,
            'error' => $error,
            'token' => $this->csrfToken($request, 'profile_password'),
        ]);
    }

    private function requireUser(Request $request, AppDatabase $db): array|RedirectResponse
    {
        $sessionUser = $request->getSession()->get('user');
        if (!$sessionUser) {
            return $this->redirectToRoute('app_login');
        }

        $user = $db->userById((int) $sessionUser['id']);
        if (!$user || (int) $user['active'] !== 1) {
            $request->getSession()->clear();
            $this->addFlash('error', 'تم تعطيل الحساب أو انتهت صلاحية الجلسة');
            return $this->redirectToRoute('app_login');
        }

        $sessionUser = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        $request->getSession()->set('user', $sessionUser);

        return $sessionUser;
    }

    private function requireAdmin(Request $request, AppDatabase $db): array|RedirectResponse
    {
        $user = $this->requireUser($request, $db);
        if ($user instanceof RedirectResponse) {
            return $user;
        }
        if (($user['role'] ?? '') !== 'admin') {
            $this->addFlash('error', 'هذه الصفحة خاصة بالمدير');
            return $this->redirectToRoute('app_dashboard');
        }
        return $user;
    }

    private function csrfToken(Request $request, string $key): string
    {
        $tokens = $request->getSession()->get('csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(24));
            $request->getSession()->set('csrf_tokens', $tokens);
        }

        return $tokens[$key];
    }

    private function verifyCsrf(Request $request, string $key): void
    {
        $tokens = $request->getSession()->get('csrf_tokens', []);
        $expected = (string) ($tokens[$key] ?? '');
        $provided = (string) $request->request->get('_token', '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('انتهت صلاحية الطلب، المرجو إعادة المحاولة');
        }
    }

    private function safeMessage(Throwable $e): string
    {
        return $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر تنفيذ العملية';
    }
}
