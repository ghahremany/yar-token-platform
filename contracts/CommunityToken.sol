// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * CommunityToken — a closed-loop token for a community, club or cooperative.
 *
 * What the administrator can do:
 *   - mint new tokens to any address
 *   - set the transfer fee, up to a hard ceiling of 10%
 *   - choose where the fee goes
 *   - pause transfers in an emergency
 *
 * What the administrator deliberately CANNOT do:
 *   - raise the fee above MAX_FEE_BPS (so holders cannot be taxed into nothing)
 *   - freeze, seize or block a specific holder
 *   - mint silently: every mint emits an event and increases a public counter
 *
 * Those limits are the difference between a community currency and a rug pull.
 * A holder can read this contract and know the worst case before buying in.
 *
 * Self-contained on purpose: no imports, so it compiles with plain solc and can be
 * pasted straight into Remix without setting up a project.
 */
contract CommunityToken {

    // ---------------------------------------------------------------- metadata
    string public name;
    string public symbol;
    uint8 public constant decimals = 18;

    // ---------------------------------------------------------------- ledger
    uint256 public totalSupply;
    mapping(address => uint256) public balanceOf;
    mapping(address => mapping(address => uint256)) public allowance;

    // ---------------------------------------------------------------- admin
    address public owner;
    address public pendingOwner;
    bool public paused;

    /// Hard ceiling on the transfer fee: 10%. Not changeable, by anyone.
    uint256 public constant MAX_FEE_BPS = 1000;
    uint256 public constant BPS_DENOMINATOR = 10000;

    uint256 public feeBps;
    address public feeCollector;

    // ---------------------------------------------------------------- counters
    uint256 public totalMinted;
    uint256 public totalFeesCollected;

    // ---------------------------------------------------------------- events
    event Transfer(address indexed from, address indexed to, uint256 value);
    event Approval(address indexed owner, address indexed spender, uint256 value);
    event Minted(address indexed to, uint256 amount, uint256 newTotalSupply);
    event Burned(address indexed from, uint256 amount, uint256 newTotalSupply);
    event FeeChanged(uint256 oldBps, uint256 newBps);
    event FeeCollectorChanged(address indexed oldCollector, address indexed newCollector);
    event Paused(address indexed by);
    event Unpaused(address indexed by);
    event OwnershipTransferStarted(address indexed from, address indexed to);
    event OwnershipTransferred(address indexed from, address indexed to);

    // ---------------------------------------------------------------- modifiers
    modifier onlyOwner() {
        require(msg.sender == owner, "CommunityToken: caller is not the owner");
        _;
    }

    modifier notPaused() {
        require(!paused, "CommunityToken: transfers are paused");
        _;
    }

    // ---------------------------------------------------------------- constructor
    constructor(
        string memory name_,
        string memory symbol_,
        uint256 initialSupply,
        address owner_,
        address feeCollector_,
        uint256 feeBps_
    ) {
        require(owner_ != address(0), "CommunityToken: owner is the zero address");
        require(feeBps_ <= MAX_FEE_BPS, "CommunityToken: fee above the ceiling");

        name = name_;
        symbol = symbol_;
        owner = owner_;
        feeCollector = feeCollector_ == address(0) ? owner_ : feeCollector_;
        feeBps = feeBps_;

        if (initialSupply > 0) {
            _mint(owner_, initialSupply);
        }
    }

    // ---------------------------------------------------------------- ERC-20
    function transfer(address to, uint256 amount) external notPaused returns (bool) {
        _transfer(msg.sender, to, amount);
        return true;
    }

    function approve(address spender, uint256 amount) external returns (bool) {
        allowance[msg.sender][spender] = amount;
        emit Approval(msg.sender, spender, amount);
        return true;
    }

    function transferFrom(address from, address to, uint256 amount) external notPaused returns (bool) {
        uint256 allowed = allowance[from][msg.sender];
        require(allowed >= amount, "CommunityToken: allowance exceeded");
        if (allowed != type(uint256).max) {
            unchecked { allowance[from][msg.sender] = allowed - amount; }
            emit Approval(from, msg.sender, allowed - amount);
        }
        _transfer(from, to, amount);
        return true;
    }

    // ---------------------------------------------------------------- admin actions
    /// Creates new tokens. Publicly visible: emits Minted and bumps totalMinted.
    function mint(address to, uint256 amount) external onlyOwner {
        _mint(to, amount);
    }

    /// Sets the fee in basis points (100 = 1%). Capped at MAX_FEE_BPS forever.
    function setFee(uint256 newBps) external onlyOwner {
        require(newBps <= MAX_FEE_BPS, "CommunityToken: fee above the ceiling");
        emit FeeChanged(feeBps, newBps);
        feeBps = newBps;
    }

    function setFeeCollector(address newCollector) external onlyOwner {
        require(newCollector != address(0), "CommunityToken: collector is the zero address");
        emit FeeCollectorChanged(feeCollector, newCollector);
        feeCollector = newCollector;
    }

    function pause() external onlyOwner {
        paused = true;
        emit Paused(msg.sender);
    }

    function unpause() external onlyOwner {
        paused = false;
        emit Unpaused(msg.sender);
    }

    /// Two-step handover, so ownership cannot be lost to a typo.
    function transferOwnership(address newOwner) external onlyOwner {
        require(newOwner != address(0), "CommunityToken: new owner is the zero address");
        pendingOwner = newOwner;
        emit OwnershipTransferStarted(owner, newOwner);
    }

    function acceptOwnership() external {
        require(msg.sender == pendingOwner, "CommunityToken: not the pending owner");
        address previous = owner;
        owner = pendingOwner;
        pendingOwner = address(0);
        emit OwnershipTransferred(previous, owner);
    }

    // ---------------------------------------------------------------- holders
    /// Anyone can burn their own tokens.
    function burn(uint256 amount) external {
        uint256 balance = balanceOf[msg.sender];
        require(balance >= amount, "CommunityToken: burn exceeds balance");
        unchecked { balanceOf[msg.sender] = balance - amount; }
        totalSupply -= amount;
        emit Transfer(msg.sender, address(0), amount);
        emit Burned(msg.sender, amount, totalSupply);
    }

    // ---------------------------------------------------------------- internals
    function _mint(address to, uint256 amount) internal {
        require(to != address(0), "CommunityToken: mint to the zero address");
        totalSupply += amount;
        totalMinted += amount;
        unchecked { balanceOf[to] += amount; }
        emit Transfer(address(0), to, amount);
        emit Minted(to, amount, totalSupply);
    }

    /**
     * Moves tokens and skims the fee.
     *
     * The fee is skipped when either side is the collector, so collected fees are never
     * taxed again and the collector can move funds out freely.
     */
    function _transfer(address from, address to, uint256 amount) internal {
        require(to != address(0), "CommunityToken: transfer to the zero address");
        uint256 fromBalance = balanceOf[from];
        require(fromBalance >= amount, "CommunityToken: insufficient balance");
        unchecked { balanceOf[from] = fromBalance - amount; }

        uint256 fee = 0;
        if (feeBps > 0 && from != feeCollector && to != feeCollector) {
            fee = (amount * feeBps) / BPS_DENOMINATOR;
        }

        if (fee > 0) {
            unchecked { balanceOf[feeCollector] += fee; }
            totalFeesCollected += fee;
            emit Transfer(from, feeCollector, fee);
        }

        unchecked { balanceOf[to] += amount - fee; }
        emit Transfer(from, to, amount - fee);
    }
}
