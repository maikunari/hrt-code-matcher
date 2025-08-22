#!/usr/bin/env python3
"""
Simple test to diagnose menu input issues
"""

print("="*60)
print("MENU INPUT TEST")
print("="*60)

print("\n1. Option One")
print("2. Option Two")
print("3. Option Three")
print("4. Option Four")
print("5. Option Five")
print("6. Option Six")
print("7. Option Seven")
print("8. Option Eight")
print("9. Option Nine")
print("0. Exit")

while True:
    choice = input("\nEnter option: ")
    print(f"You entered: '{choice}' (length: {len(choice)}, ASCII: {[ord(c) for c in choice]})")
    
    if choice == '0':
        print("Exiting...")
        break
    elif choice in ['1','2','3','4','5','6','7','8','9']:
        print(f"Option {choice} selected successfully!")
    else:
        print("Invalid option")