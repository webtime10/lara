<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use Illuminate\Http\Request;

class ManufacturerController extends Controller
{
    public function index()
    {
        $pageTitle = 'Производители';
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->paginate(30);

        return view('admin.manufacturers.index', compact('manufacturers', 'pageTitle'));
    }

    public function create()
    {
        $pageTitle = 'Производитель — создание';

        return view('admin.manufacturers.create', compact('pageTitle'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:64',
            'image' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        Manufacturer::create([
            'name' => $request->name,
            'image' => $request->image,
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return redirect()->route('admin.manufacturers.index')->with('success', 'Создано');
    }

    public function edit(string $id)
    {
        $manufacturer = Manufacturer::findOrFail($id);
        $pageTitle = 'Производитель — редактирование';

        return view('admin.manufacturers.edit', compact('manufacturer', 'pageTitle'));
    }

    public function update(Request $request, string $id)
    {
        $manufacturer = Manufacturer::findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:64',
            'image' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $manufacturer->update([
            'name' => $request->name,
            'image' => $request->image,
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return redirect()->route('admin.manufacturers.index')->with('success', 'Сохранено');
    }

    public function destroy(string $id)
    {
        Manufacturer::findOrFail($id)->delete();

        return redirect()->route('admin.manufacturers.index')->with('success', 'Удалено');
    }
}
